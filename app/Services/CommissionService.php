<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\CommissionRule;
use App\Models\Payment;
use App\Models\SalesTarget;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommissionService
{
    private const AUTO_WITHDRAWN = '[auto] Target no longer met';

    public function __construct(
        private AuditLogger $auditLogger,
        private TargetService $targets,
    ) {}

    public function accrueFromPayment(Payment $payment, ?User $actor = null): ?Commission
    {
        if ($payment->status !== Payment::STATUS_VERIFIED) {
            return null;
        }

        $payment->loadMissing(['invoice.order.visit', 'invoice.shop', 'shop']);

        return DB::transaction(function () use ($payment, $actor) {
            $existing = Commission::query()
                ->where('payment_id', $payment->id)
                ->where('type', Commission::TYPE_COLLECTION)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing->load(['salesman', 'payment']);
            }

            $salesmanId = $this->resolveSalesmanId($payment);

            if (! $salesmanId) {
                $this->auditLogger->log(
                    'commissions',
                    'skipped',
                    "Commission skipped for {$payment->number} — no salesman on order/shop",
                    $payment,
                    null,
                    ['payment_id' => $payment->id, 'shop_id' => $payment->shop_id],
                    $actor,
                );

                return null;
            }

            $rule = CommissionRule::activeDefault();
            $payment->loadMissing(['invoice.items', 'invoice.order']);
            $basis = $rule?->basis ?: CommissionRule::BASIS_COLLECTION;
            $baseAmount = (float) $payment->amount;
            $rate = (float) ($rule?->collection_rate_percent ?? 3.5);

            if ($basis === CommissionRule::BASIS_SALES) {
                $rate = (float) ($rule?->sales_rate_percent ?? $rule?->collection_rate_percent ?? 3.5);
                $baseAmount = (float) ($payment->invoice?->order?->total ?? $payment->invoice?->total ?? $payment->amount);
            } elseif ($basis === CommissionRule::BASIS_PRODUCT && $rule?->product_id) {
                $rate = (float) ($rule?->product_rate_percent ?? $rule?->collection_rate_percent ?? 3.5);
                $baseAmount = (float) ($payment->invoice?->items
                    ?->where('product_id', $rule->product_id)
                    ->sum('line_total') ?? 0);
            }

            $amount = round($baseAmount * $rate / 100, 2);

            if ($amount <= 0) {
                return null;
            }

            $when = $payment->verified_at ?? now();
            $year = (int) $when->year;
            $month = (int) $when->month;
            $order = $payment->invoice?->order;

            $salesman = User::query()->findOrFail($salesmanId);
            $target = $this->targets->ensureForSalesman($salesman, $year, $month, $actor);

            $commission = Commission::query()->create([
                'number' => $this->nextNumber(),
                'salesman_id' => $salesmanId,
                'sales_target_id' => $target->id,
                'payment_id' => $payment->id,
                'order_id' => $order?->id,
                'invoice_id' => $payment->invoice_id,
                'type' => Commission::TYPE_COLLECTION,
                'year' => $year,
                'month' => $month,
                // Store the same base used to compute commission_amount (basis may differ from payment amount).
                'base_amount' => round($baseAmount, 2),
                'rate_percent' => $rate,
                'commission_amount' => $amount,
                'status' => Commission::STATUS_ACCRUED,
                'notes' => 'Accrued on verified payment '.$payment->number,
                'created_by' => $actor?->id,
            ]);

            $this->targets->recalculate($target);

            $this->auditLogger->log(
                'commissions',
                'accrued',
                "Commission {$commission->number} accrued",
                $commission,
                null,
                ['amount' => $amount, 'payment_id' => $payment->id, 'salesman_id' => $salesmanId],
                $actor,
            );

            try {
                app(AppNotificationService::class)->commissionAccrued($commission->fresh(['salesman', 'payment']));
            } catch (\Throwable) {
                // non-blocking
            }

            return $commission->fresh(['salesman', 'payment']);
        });
    }

    /**
     * Accrue any missing collection commissions for verified payments.
     *
     * @return array{created:int, skipped:int, existing:int}
     */
    public function syncFromVerifiedPayments(?User $actor = null): array
    {
        $created = 0;
        $skipped = 0;
        $existing = 0;

        Payment::query()
            ->where('status', Payment::STATUS_VERIFIED)
            ->with(['invoice.order.visit', 'invoice.shop', 'shop'])
            ->orderBy('id')
            ->each(function (Payment $payment) use ($actor, &$created, &$skipped, &$existing) {
                $had = Commission::query()
                    ->where('payment_id', $payment->id)
                    ->where('type', Commission::TYPE_COLLECTION)
                    ->exists();

                $result = $this->accrueFromPayment($payment, $actor);

                if ($had) {
                    $existing++;
                } elseif ($result) {
                    $created++;
                } else {
                    $skipped++;
                }
            });

        return compact('created', 'skipped', 'existing');
    }

    public function maybeAccrueTargetBonus(SalesTarget $target, ?User $actor = null): ?Commission
    {
        return DB::transaction(function () use ($target, $actor) {
            $target = SalesTarget::query()->lockForUpdate()->findOrFail($target->id);

            if (! $target->target_met) {
                return null;
            }

            $existing = Commission::query()
                ->where('sales_target_id', $target->id)
                ->where('type', Commission::TYPE_TARGET_BONUS)
                ->lockForUpdate()
                ->first();

            $rule = CommissionRule::activeDefault();
            $rate = (float) ($rule?->target_bonus_percent ?? 1.0);
            $base = (float) $target->collected_amount;
            if ($base <= 0) {
                $base = (float) $target->achieved_amount;
            }
            $amount = round($base * $rate / 100, 2);

            if ($existing) {
                if ($existing->status === Commission::STATUS_REJECTED && str_contains((string) $existing->notes, self::AUTO_WITHDRAWN) && $amount > 0) {
                    $existing->update([
                        'status' => Commission::STATUS_ACCRUED,
                        'base_amount' => $base,
                        'rate_percent' => $rate,
                        'commission_amount' => $amount,
                        'approved_by' => null,
                        'approved_at' => null,
                        'notes' => trim(str_replace(self::AUTO_WITHDRAWN, '', (string) $existing->notes))."\nRestored: target met again",
                    ]);
                    $this->auditLogger->log('commissions', 'bonus', "Target bonus {$existing->number} restored", $existing, null, ['amount' => $amount], $actor);
                } elseif ($existing->status === Commission::STATUS_ACCRUED && abs((float) $existing->commission_amount - $amount) >= 0.01 && $amount > 0) {
                    $existing->update(['base_amount' => $base, 'commission_amount' => $amount, 'rate_percent' => $rate]);
                }

                return $existing->fresh();
            }

            if ($amount <= 0) {
                return null;
            }

            $commission = Commission::query()->create([
                'number' => $this->nextNumber(),
                'salesman_id' => $target->salesman_id,
                'sales_target_id' => $target->id,
                'type' => Commission::TYPE_TARGET_BONUS,
                'year' => $target->year,
                'month' => $target->month,
                'base_amount' => $base,
                'rate_percent' => $rate,
                'commission_amount' => $amount,
                'status' => Commission::STATUS_ACCRUED,
                'notes' => 'Target met bonus for '.$target->periodLabel(),
                'created_by' => $actor?->id,
            ]);

            $this->auditLogger->log('commissions', 'bonus', "Target bonus {$commission->number}", $commission, null, ['amount' => $amount], $actor);

            return $commission;
        });
    }

    /** Reject a not-yet-approved target bonus once the salesman drops back under target. */
    public function withdrawTargetBonus(SalesTarget $target, ?User $actor = null): ?Commission
    {
        if ($target->target_met) {
            return null;
        }

        return DB::transaction(function () use ($target, $actor) {
            $bonus = Commission::query()
                ->where('sales_target_id', $target->id)
                ->where('type', Commission::TYPE_TARGET_BONUS)
                ->where('status', Commission::STATUS_ACCRUED)
                ->lockForUpdate()
                ->first();

            if (! $bonus) {
                return null;
            }

            $bonus->update([
                'status' => Commission::STATUS_REJECTED,
                'notes' => trim(($bonus->notes ? $bonus->notes."\n" : '').self::AUTO_WITHDRAWN),
            ]);
            $this->auditLogger->log('commissions', 'rejected', "Target bonus {$bonus->number} withdrawn — target no longer met", $bonus, null, null, $actor);

            return $bonus;
        });
    }

    public function approve(Commission $commission, ?User $actor = null): Commission
    {
        if ($commission->status !== Commission::STATUS_ACCRUED) {
            throw ValidationException::withMessages(['commission' => 'Only accrued commissions can be approved.']);
        }

        $commission->update([
            'status' => Commission::STATUS_APPROVED,
            'approved_at' => now(),
            'approved_by' => $actor?->id,
        ]);

        $this->auditLogger->log('commissions', 'approved', "Commission {$commission->number} approved", $commission, null, null, $actor);

        return $commission->fresh();
    }

    public function markPaid(Commission $commission, ?User $actor = null): Commission
    {
        if ($commission->status !== Commission::STATUS_APPROVED) {
            throw ValidationException::withMessages(['commission' => 'Only approved commissions can be marked paid.']);
        }

        $commission->update([
            'status' => Commission::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $this->auditLogger->log('commissions', 'paid', "Commission {$commission->number} paid", $commission, null, null, $actor);

        return $commission->fresh();
    }

    public function reject(Commission $commission, string $reason, ?User $actor = null): Commission
    {
        if ($commission->status === Commission::STATUS_PAID) {
            throw ValidationException::withMessages(['commission' => 'Paid commissions cannot be rejected.']);
        }

        if ($commission->status === Commission::STATUS_REJECTED) {
            throw ValidationException::withMessages(['commission' => 'Commission is already rejected.']);
        }

        $commission->update([
            'status' => Commission::STATUS_REJECTED,
            'notes' => trim(($commission->notes ? $commission->notes."\n" : '').'Rejected: '.$reason),
            'approved_by' => $actor?->id,
            'approved_at' => now(),
        ]);

        $this->auditLogger->log('commissions', 'rejected', "Commission {$commission->number} rejected", $commission, null, ['reason' => $reason], $actor);

        return $commission->fresh();
    }

    /**
     * Order salesman → visit salesman → invoice shop → payment shop.
     */
    public function resolveSalesmanId(Payment $payment): ?int
    {
        $payment->loadMissing(['invoice.order.visit', 'invoice.shop', 'shop']);

        $order = $payment->invoice?->order;

        $id = $order?->salesman_id
            ?: $order?->visit?->salesman_id
            ?: $payment->invoice?->shop?->assigned_salesman_id
            ?: $payment->shop?->assigned_salesman_id;

        return $id ? (int) $id : null;
    }

    public function nextNumber(): string
    {
        return \App\Support\DocumentNumber::next(Commission::class, 'COM-'.now()->format('ymd').'-', 4);
    }

    /**
     * @return array{accrued:float, approved:float, paid:float, rejected:float, count_accrued:int, count_approved:int, count_paid:int}
     */
    public function pipelineTotals(): array
    {
        $rows = Commission::query()
            ->selectRaw('status, COUNT(*) as cnt, COALESCE(SUM(commission_amount), 0) as total')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $amount = fn (string $status) => (float) ($rows[$status]->total ?? 0);
        $count = fn (string $status) => (int) ($rows[$status]->cnt ?? 0);

        return [
            'accrued' => $amount(Commission::STATUS_ACCRUED),
            'approved' => $amount(Commission::STATUS_APPROVED),
            'paid' => $amount(Commission::STATUS_PAID),
            'rejected' => $amount(Commission::STATUS_REJECTED),
            'count_accrued' => $count(Commission::STATUS_ACCRUED),
            'count_approved' => $count(Commission::STATUS_APPROVED),
            'count_paid' => $count(Commission::STATUS_PAID),
        ];
    }
}
