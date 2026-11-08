<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        private AuditLogger $auditLogger,
        private InvoiceService $invoices,
        private CreditService $credit,
        private CommissionService $commissions,
    ) {}

    public function record(array $data, ?User $actor = null): Payment
    {
        $amount = (float) $data['amount'];
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Payment amount must be positive.']);
        }

        $payment = DB::transaction(function () use ($data, $amount, $actor) {
            $payment = Payment::query()->create([
                'number' => $this->nextNumber(),
                'shop_id' => $data['shop_id'],
                'invoice_id' => $data['invoice_id'] ?? null,
                'amount' => $amount,
                'method' => $data['method'] ?? Payment::METHOD_BANK,
                'reference' => $data['reference'] ?? null,
                'status' => Payment::STATUS_PENDING,
                'paid_at' => $data['paid_at'] ?? now(),
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor?->id,
            ]);

            $this->auditLogger->log(
                'payments',
                'recorded',
                "Payment {$payment->number} recorded",
                $payment,
                null,
                ['amount' => $amount, 'shop_id' => $payment->shop_id],
                $actor,
            );

            return $payment->fresh(['shop', 'invoice']);
        });

        $applyNow = (bool) ($data['apply_now'] ?? false);

        // Apply immediately for cash-on-delivery, or for advance collected by someone who may verify.
        if ($payment->invoice_id && $applyNow) {
            return $this->verify($payment, $actor);
        }

        if ($payment->invoice_id && $actor?->can('payments.verify') && $this->isAdvanceInvoice((int) $payment->invoice_id)) {
            return $this->verify($payment, $actor);
        }

        DB::afterCommit(function () use ($payment) {
            try {
                app(AppNotificationService::class)->paymentPending($payment);
            } catch (\Throwable) {
                // non-blocking
            }
        });

        return $payment;
    }

    /**
     * Record a payment against a sales invoice after goods have reached the shop.
     */
    public function collectAgainstInvoice(
        Invoice $invoice,
        float $amount,
        User $actor,
        string $method = Payment::METHOD_CASH,
        ?string $reference = null,
        ?string $notes = null,
        bool $applyNow = false,
    ): Payment {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Payment amount must be positive.']);
        }

        $invoice->refresh();
        $pending = (float) Payment::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', Payment::STATUS_PENDING)
            ->sum('amount');
        $available = round((float) $invoice->balance - $pending, 2);

        if ($available <= 0.009) {
            throw ValidationException::withMessages([
                'amount' => $pending > 0.009
                    ? 'A payment is already waiting for office confirmation.'
                    : 'This bill is already settled.',
            ]);
        }

        if ($amount > $available + 0.01) {
            throw ValidationException::withMessages([
                'amount' => 'Amount cannot exceed the remaining bill (৳ '.number_format($available, 2).').',
            ]);
        }

        if (! in_array($method, Payment::METHODS, true)) {
            throw ValidationException::withMessages(['method' => 'Choose a valid payment method.']);
        }

        return $this->record([
            'shop_id' => $invoice->shop_id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'method' => $method,
            'reference' => $reference,
            'notes' => $notes,
            'apply_now' => $applyNow,
        ], $actor);
    }

    public function verify(Payment $payment, ?User $actor = null): Payment
    {
        if ($payment->status === Payment::STATUS_VERIFIED) {
            return $payment;
        }

        if ($payment->status === Payment::STATUS_REJECTED) {
            throw ValidationException::withMessages(['payment' => 'Rejected payment cannot be verified.']);
        }

        return DB::transaction(function () use ($payment, $actor) {
            $payment = Payment::query()->lockForUpdate()->with(['invoice', 'shop'])->findOrFail($payment->id);

            if ($payment->status === Payment::STATUS_VERIFIED) {
                return $payment->fresh(['shop', 'invoice', 'verifier']);
            }
            if ($payment->status !== Payment::STATUS_PENDING) {
                throw ValidationException::withMessages(['payment' => 'Only pending payments can be verified.']);
            }

            if ($payment->invoice_id) {
                $invoice = Invoice::query()->lockForUpdate()->findOrFail($payment->invoice_id);
                if ($payment->amount > ((float) $invoice->balance + 0.01)) {
                    throw ValidationException::withMessages([
                        'amount' => 'Payment exceeds invoice balance (৳ '.number_format($invoice->balance, 2).').',
                    ]);
                }
                $this->invoices->applyPayment($invoice, (float) $payment->amount);

                $payment->update([
                    'status' => Payment::STATUS_VERIFIED,
                    'verified_at' => now(),
                    'verified_by' => $actor?->id,
                ]);
            } else {
                // Shop-level "pay due" — apply oldest open invoices first so Due and invoice balances stay in sync.
                $payment = $this->allocateShopPaymentToOpenInvoices($payment, $actor);
            }

            $this->credit->recalculateOutstanding($payment->shop);

            $this->commissions->accrueFromPayment($payment->fresh(['invoice.order.visit', 'invoice.shop', 'shop']), $actor);

            if ($payment->invoice_id) {
                $advanceOrder = \App\Models\Order::query()
                    ->where('advance_invoice_id', $payment->invoice_id)
                    ->first();
                if ($advanceOrder) {
                    app(\App\Services\OrderService::class)->refreshAdvanceStatus($advanceOrder);
                }
            }

            $this->auditLogger->log(
                'payments',
                'verified',
                "Payment {$payment->number} verified",
                $payment,
                null,
                ['amount' => (float) $payment->amount, 'invoice_id' => $payment->invoice_id],
                $actor,
            );

            $fresh = $payment->fresh(['shop', 'invoice', 'verifier']);

            DB::afterCommit(function () use ($fresh) {
                try {
                    app(AppNotificationService::class)->paymentVerified($fresh);
                } catch (\Throwable) {
                    // non-blocking
                }
            });

            return $fresh;
        });
    }

    /**
     * Apply an unallocated shop payment across open invoices (oldest first).
     * Leftover amount stays as shop credit when it exceeds open balances.
     */
    private function allocateShopPaymentToOpenInvoices(Payment $payment, ?User $actor): Payment
    {
        $remaining = round((float) $payment->amount, 2);
        $openInvoices = Invoice::query()
            ->where('shop_id', $payment->shop_id)
            ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIAL])
            ->orderByRaw('COALESCE(due_at, issued_at, created_at)')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($openInvoices->isEmpty() || $remaining <= 0.009) {
            $payment->update([
                'status' => Payment::STATUS_VERIFIED,
                'verified_at' => now(),
                'verified_by' => $actor?->id,
            ]);

            return $payment->fresh(['shop', 'invoice']);
        }

        $first = true;
        $primary = $payment;

        foreach ($openInvoices as $invoice) {
            if ($remaining <= 0.009) {
                break;
            }

            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $apply = round(min($remaining, (float) $invoice->balance), 2);
            if ($apply <= 0.009) {
                continue;
            }

            $this->invoices->applyPayment($invoice, $apply);

            if ($first) {
                $payment->update([
                    'invoice_id' => $invoice->id,
                    'amount' => $apply,
                    'status' => Payment::STATUS_VERIFIED,
                    'verified_at' => now(),
                    'verified_by' => $actor?->id,
                    'notes' => trim(($payment->notes ? $payment->notes."\n" : '').'Applied to open dues (oldest first).'),
                ]);
                $primary = $payment->fresh(['shop', 'invoice']);
                $first = false;
            } else {
                Payment::query()->create([
                    'number' => $this->nextNumber(),
                    'shop_id' => $payment->shop_id,
                    'invoice_id' => $invoice->id,
                    'amount' => $apply,
                    'method' => $payment->method,
                    'reference' => $payment->reference,
                    'status' => Payment::STATUS_VERIFIED,
                    'paid_at' => $payment->paid_at ?? now(),
                    'verified_at' => now(),
                    'verified_by' => $actor?->id,
                    'notes' => 'Split from '.$payment->number.' · applied to open dues',
                    'created_by' => $actor?->id ?? $payment->created_by,
                ]);
            }

            $remaining = round($remaining - $apply, 2);
        }

        if ($first) {
            // No invoice had a positive balance — keep as shop credit.
            $payment->update([
                'status' => Payment::STATUS_VERIFIED,
                'verified_at' => now(),
                'verified_by' => $actor?->id,
            ]);

            return $payment->fresh(['shop', 'invoice']);
        }

        if ($remaining > 0.009) {
            Payment::query()->create([
                'number' => $this->nextNumber(),
                'shop_id' => $payment->shop_id,
                'invoice_id' => null,
                'amount' => $remaining,
                'method' => $payment->method,
                'reference' => $payment->reference,
                'status' => Payment::STATUS_VERIFIED,
                'paid_at' => $payment->paid_at ?? now(),
                'verified_at' => now(),
                'verified_by' => $actor?->id,
                'notes' => 'Unapplied remainder from '.$payment->number,
                'created_by' => $actor?->id ?? $payment->created_by,
            ]);
        }

        return $primary->fresh(['shop', 'invoice']);
    }

    private function isAdvanceInvoice(int $invoiceId): bool
    {
        return \App\Models\Order::query()
            ->where('advance_invoice_id', $invoiceId)
            ->exists();
    }

    public function reject(Payment $payment, string $reason, ?User $actor = null): Payment
    {
        if ($payment->status !== Payment::STATUS_PENDING) {
            throw ValidationException::withMessages(['payment' => 'Only pending payments can be rejected.']);
        }

        return DB::transaction(function () use ($payment, $reason, $actor) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== Payment::STATUS_PENDING) {
                throw ValidationException::withMessages(['payment' => 'Only pending payments can be rejected.']);
            }

            $payment->update([
                'status' => Payment::STATUS_REJECTED,
                'rejection_reason' => $reason,
                'verified_by' => $actor?->id,
                'verified_at' => now(),
            ]);

            $this->auditLogger->log('payments', 'rejected', "Payment {$payment->number} rejected", $payment, null, ['reason' => $reason], $actor);

            return $payment->fresh();
        });
    }

    public function nextNumber(): string
    {
        return \App\Support\DocumentNumber::next(Payment::class, 'PAY-'.now()->format('ymd').'-', 4);
    }
}
