<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductReturn;
use App\Models\SalesTarget;
use App\Models\SalesmanProfile;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class TargetService
{
    public function __construct(
        private AuditLogger $auditLogger,
        private RewardService $rewards,
    ) {}

    public function ensureForSalesman(User $salesman, int $year, int $month, ?User $actor = null): SalesTarget
    {
        $profile = $salesman->salesmanProfile;
        $existing = SalesTarget::query()
            ->where('salesman_id', $salesman->id)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        if ($existing) {
            return $existing;
        }

        return SalesTarget::query()->create([
            'salesman_id' => $salesman->id,
            'territory_id' => $profile?->territory_id,
            'year' => $year,
            'month' => $month,
            'target_amount' => (float) ($profile?->monthly_target ?? 0),
            'achieved_amount' => 0,
            'collected_amount' => 0,
            'status' => SalesTarget::STATUS_OPEN,
            'target_met' => false,
            'created_by' => $actor?->id,
        ]);
    }

    public function upsert(array $data, ?User $actor = null): SalesTarget
    {
        $salesmanId = (int) $data['salesman_id'];
        $year = (int) $data['year'];
        $month = (int) $data['month'];

        if ($month < 1 || $month > 12) {
            throw ValidationException::withMessages(['month' => 'Month must be 1–12.']);
        }

        $target = SalesTarget::query()->updateOrCreate(
            ['salesman_id' => $salesmanId, 'year' => $year, 'month' => $month],
            [
                'territory_id' => $data['territory_id'] ?? User::query()->find($salesmanId)?->salesmanProfile?->territory_id,
                'target_amount' => (float) $data['target_amount'],
                'created_by' => $actor?->id,
            ],
        );

        $this->recalculate($target);

        $this->auditLogger->log(
            'targets',
            'upserted',
            "Target {$target->periodLabel()} for salesman #{$salesmanId}",
            $target,
            null,
            ['target_amount' => (float) $target->target_amount],
            $actor,
        );

        return $target->fresh(['salesman', 'territory']);
    }

    /** Recalculate the salesman's existing target for the month containing $when (never creates one). */
    public function recalculateFor(?int $salesmanId, ?CarbonInterface $when = null): ?SalesTarget
    {
        if (! $salesmanId) {
            return null;
        }
        $when ??= now();

        $target = SalesTarget::query()
            ->where('salesman_id', $salesmanId)
            ->where('year', $when->year)
            ->where('month', $when->month)
            ->first();

        return $target ? $this->recalculate($target) : null;
    }

    /** Delivered sales in the window minus returns approved in the same window. */
    public function achievedAmount(int $salesmanId, CarbonInterface $start, CarbonInterface $end): float
    {
        $delivered = (float) Order::query()
            ->where('salesman_id', $salesmanId)
            ->where('status', Order::STATUS_DELIVERED)
            ->where(function ($q) use ($start, $end) {
                $q->whereHas('statusHistories', function ($h) use ($start, $end) {
                    $h->where('to_status', Order::STATUS_DELIVERED)
                        ->whereBetween('created_at', [$start, $end]);
                })->orWhere(function ($o) use ($start, $end) {
                    $o->whereDoesntHave('statusHistories', fn ($h) => $h->where('to_status', Order::STATUS_DELIVERED))
                        ->whereBetween('submitted_at', [$start, $end]);
                });
            })
            ->sum('total');

        $returned = (float) ProductReturn::query()
            ->whereIn('status', [ProductReturn::STATUS_APPROVED, ProductReturn::STATUS_RESTOCKED, ProductReturn::STATUS_COMPLETED])
            ->whereBetween('approved_at', [$start, $end])
            ->whereHas('order', fn ($o) => $o->where('salesman_id', $salesmanId))
            ->sum('total');

        return max(0, round($delivered - $returned, 2));
    }

    public function recalculate(SalesTarget $target): SalesTarget
    {
        $start = now()->setDate($target->year, $target->month, 1)->startOfMonth();
        $end = (clone $start)->endOfMonth();

        $achieved = $this->achievedAmount((int) $target->salesman_id, $start, $end);

        $sid = (int) $target->salesman_id;
        $collection = fn ($c) => $c->where('type', Commission::TYPE_COLLECTION)->where('status', '!=', Commission::STATUS_REJECTED);

        // Live attribution for payments without a collection commission:
        // the order's salesman first, the shop's assigned salesman only when the order has none.
        $liveAttribution = function ($q) use ($sid) {
            $q->whereHas('invoice.order', fn ($o) => $o->where('salesman_id', $sid))
                ->orWhere(function ($fallback) use ($sid) {
                    $fallback->where(function ($noOrderSalesman) {
                        $noOrderSalesman
                            ->whereDoesntHave('invoice')
                            ->orWhereHas('invoice', function ($inv) {
                                $inv->where(function ($i) {
                                    $i->whereNull('order_id')
                                        ->orWhereHas('order', fn ($o) => $o->whereNull('salesman_id'));
                                });
                            });
                    })->where(function ($shopAttr) use ($sid) {
                        $shopAttr
                            ->whereHas('invoice.shop', fn ($s) => $s->where('assigned_salesman_id', $sid))
                            ->orWhereHas('shop', fn ($s) => $s->where('assigned_salesman_id', $sid));
                    });
                });
        };

        $collected = (float) Payment::query()
            ->where('status', Payment::STATUS_VERIFIED)
            ->whereBetween('verified_at', [$start, $end])
            ->where(function ($q) use ($sid, $collection, $liveAttribution) {
                // A collection commission freezes who collected the money, so a later shop reassignment doesn't move it.
                $q->whereHas('commissions', fn ($c) => $collection($c)->where('salesman_id', $sid))
                    ->orWhere(fn ($live) => $live->whereDoesntHave('commissions', $collection)->where($liveAttribution));
            })
            ->sum('amount');

        $wasMet = (bool) $target->target_met;
        $met = (float) $target->target_amount > 0 && $achieved >= (float) $target->target_amount;

        $target->update([
            'achieved_amount' => round($achieved, 2),
            'collected_amount' => round($collected, 2),
            'target_met' => $met,
        ]);

        // Resolved lazily: CommissionService itself depends on this service.
        $commissions = app(CommissionService::class);
        if ($met) {
            $this->rewards->ensureTargetHitReward($target->fresh());
            $commissions->maybeAccrueTargetBonus($target->fresh());
            if (! $wasMet) {
                try {
                    app(AppNotificationService::class)->targetAchieved(
                        User::query()->find($target->salesman_id),
                        (float) $achieved,
                        (float) $target->target_amount,
                    );
                } catch (\Throwable) {
                    // non-blocking
                }
            }
        } else {
            $this->rewards->withdrawTargetHitReward($target->fresh());
            $commissions->withdrawTargetBonus($target->fresh());
        }

        return $target->fresh();
    }

    public function recalculatePeriod(int $year, int $month): void
    {
        SalesTarget::query()
            ->where('year', $year)
            ->where('month', $month)
            ->get()
            ->each(fn (SalesTarget $t) => $this->recalculate($t));
    }

    public function seedCurrentMonthFromProfiles(?User $actor = null): int
    {
        $year = (int) now()->year;
        $month = (int) now()->month;
        $count = 0;

        SalesmanProfile::query()
            ->where('is_active', true)
            ->with('user')
            ->get()
            ->each(function (SalesmanProfile $profile) use ($year, $month, $actor, &$count) {
                if (! $profile->user) {
                    return;
                }
                $this->ensureForSalesman($profile->user, $year, $month, $actor);
                $count++;
            });

        $this->recalculatePeriod($year, $month);

        return $count;
    }
}
