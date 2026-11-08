<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_AUDIT = 'pending_audit';

    public const STATUS_AWAITING_ADVANCE = 'awaiting_advance';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PICKING = 'picking';

    public const STATUS_PICKED = 'picked';

    public const STATUS_PACKED = 'packed';

    public const STATUS_DISPATCHED = 'dispatched';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const SOURCE_SALESMAN = 'salesman';

    public const SOURCE_SHOP_PORTAL = 'shop_portal';

    public const SOURCE_ADMIN = 'admin';

    protected $fillable = [
        'number', 'shop_id', 'warehouse_id', 'invoice_id', 'salesman_id', 'visit_id', 'source', 'status',
        'subtotal', 'discount_total', 'total', 'item_count', 'notes',
        'submitted_at', 'created_by', 'audited_by', 'audited_at',
        'stock_reserved_at', 'audit_notes', 'rejection_reason',
        'credit_available_at_audit', 'credit_override', 'stock_reserved',
        'advance_required', 'advance_amount', 'advance_invoice_id',
        'advance_requested_at', 'advance_paid_at',
        'cancelled_by', 'cancelled_at', 'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'total' => 'decimal:2',
            'credit_available_at_audit' => 'decimal:2',
            'credit_override' => 'boolean',
            'stock_reserved' => 'boolean',
            'advance_required' => 'boolean',
            'advance_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'audited_at' => 'datetime',
            'stock_reserved_at' => 'datetime',
            'advance_requested_at' => 'datetime',
            'advance_paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function fulfilment(): HasOne
    {
        return $this->hasOne(OrderFulfilment::class);
    }

    public function delivery(): HasOne
    {
        return $this->hasOne(Delivery::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function advanceInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'advance_invoice_id');
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesman_id');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(ShopVisit::class, 'visit_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function auditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'audited_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->latest();
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_PENDING_AUDIT => 'Pending Super Admin Audit',
            self::STATUS_AWAITING_ADVANCE => $this->isAdvancePaid()
                ? 'Advance Paid · Ready to Approve'
                : 'Awaiting Advance Payment',
            self::STATUS_APPROVED,
            self::STATUS_PICKING,
            self::STATUS_PICKED,
            self::STATUS_PACKED,
            self::STATUS_DISPATCHED => 'Approved',
            self::STATUS_DELIVERED => 'Delivered',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_CANCELLED => 'Cancelled',
            default => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }

    /**
     * Plain-language status for salesmen and shops.
     *
     * @return array{label: string, tone: string, step: int}
     */
    public function simpleStatus(): array
    {
        return match ($this->status) {
            self::STATUS_PENDING_AUDIT, self::STATUS_DRAFT, self::STATUS_AWAITING_ADVANCE => ['label' => 'Waiting for approval', 'tone' => 'amber', 'step' => 1],
            self::STATUS_APPROVED, self::STATUS_PICKING, self::STATUS_PICKED, self::STATUS_PACKED, self::STATUS_DISPATCHED => ['label' => 'Approved', 'tone' => 'blue', 'step' => 2],
            self::STATUS_DELIVERED => ['label' => 'Delivered', 'tone' => 'green', 'step' => 3],
            self::STATUS_REJECTED => ['label' => 'Rejected', 'tone' => 'red', 'step' => 0],
            self::STATUS_CANCELLED => ['label' => 'Cancelled', 'tone' => 'gray', 'step' => 0],
            default => ['label' => $this->statusLabel(), 'tone' => 'gray', 'step' => 0],
        };
    }

    public function isPendingAudit(): bool
    {
        return $this->status === self::STATUS_PENDING_AUDIT;
    }

    public function isAwaitingAdvance(): bool
    {
        return $this->status === self::STATUS_AWAITING_ADVANCE;
    }

    public function isAdvancePaid(): bool
    {
        if (! $this->advance_required) {
            return true;
        }

        if ($this->advance_paid_at) {
            return true;
        }

        $invoice = $this->relationLoaded('advanceInvoice')
            ? $this->advanceInvoice
            : $this->advanceInvoice()->first();

        if (! $invoice) {
            return false;
        }

        return (float) $invoice->balance <= 0.009;
    }

    /**
     * Order total vs advance paid vs amount still owed.
     *
     * @return array{
     *     order_total: float,
     *     advance_required: float,
     *     advance_paid: float,
     *     sales_paid: float,
     *     remaining_due: float,
     *     has_advance: bool,
     *     advance_cleared: bool,
     *     fully_settled: bool,
     *     invoice_issued: bool,
     *     collectible: bool
     * }
     */
    public function settlement(): array
    {
        $orderTotal = round((float) $this->total, 2);
        $advanceRequired = round((float) ($this->advance_amount ?: 0), 2);

        $advanceInvoice = $this->relationLoaded('advanceInvoice')
            ? $this->advanceInvoice
            : $this->advanceInvoice()->first();

        $advancePaid = 0.0;
        if ($advanceInvoice) {
            $advancePaid = round((float) $advanceInvoice->paid_amount, 2);
        } elseif ($this->advance_paid_at && $advanceRequired > 0) {
            $advancePaid = $advanceRequired;
        }

        $salesInvoice = $this->relationLoaded('invoice')
            ? $this->invoice
            : $this->invoice()->first();

        $invoiceIssued = $salesInvoice && (int) $salesInvoice->id !== (int) ($this->advance_invoice_id ?: 0);

        $salesPaid = 0.0;
        $remainingDue = max(0, round($orderTotal - $advancePaid, 2));

        if ($invoiceIssued) {
            $salesPaid = round((float) $salesInvoice->paid_amount, 2);
            $remainingDue = round((float) $salesInvoice->balance, 2);
        }

        $hasAdvance = $advanceRequired > 0.009 || (bool) $this->advance_required;

        $pendingSales = 0.0;
        if ($invoiceIssued && $salesInvoice) {
            $pendingSales = round((float) Payment::query()
                ->where('invoice_id', $salesInvoice->id)
                ->where('status', Payment::STATUS_PENDING)
                ->sum('amount'), 2);
        }

        $baki = max(0, round($remainingDue - $pendingSales, 2));

        return [
            'order_total' => $orderTotal,
            'advance_required' => $advanceRequired,
            'advance_paid' => $advancePaid,
            'sales_paid' => $salesPaid,
            'pending_sales' => $pendingSales,
            'paid_to_date' => round($advancePaid + $salesPaid, 2),
            'remaining_due' => $remainingDue,
            'baki' => $baki,
            'has_advance' => $hasAdvance,
            'advance_cleared' => $advanceRequired <= 0.009 || $advancePaid + 0.009 >= $advanceRequired,
            'fully_settled' => $remainingDue <= 0.009 && ($invoiceIssued || $hasAdvance || $advancePaid > 0.009),
            'invoice_issued' => (bool) $invoiceIssued,
            'collectible' => $remainingDue > 0.009 && ! in_array($this->status, [self::STATUS_REJECTED, self::STATUS_CANCELLED], true),
        ];
    }

    public function isDelivered(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }

    public function goodsAreAtTheShop(): bool
    {
        if ($this->isDelivered()) {
            return true;
        }

        $delivery = $this->relationLoaded('delivery')
            ? $this->delivery
            : $this->delivery()->first();

        return $delivery && $delivery->status === Delivery::STATUS_OUT_FOR_DELIVERY;
    }

    public function pendingBillPayment(): ?Payment
    {
        if (! $this->invoice_id) {
            return null;
        }

        return Payment::query()
            ->where('invoice_id', $this->invoice_id)
            ->where('status', Payment::STATUS_PENDING)
            ->latest('id')
            ->first();
    }

    public function collectibleBalance(): float
    {
        if (in_array($this->status, [self::STATUS_REJECTED, self::STATUS_CANCELLED], true)) {
            return 0.0;
        }

        return (float) $this->settlement()['baki'];
    }

    public function canDeliver(): bool
    {
        return in_array($this->status, [
            self::STATUS_APPROVED,
            self::STATUS_PICKING,
            self::STATUS_PICKED,
            self::STATUS_PACKED,
            self::STATUS_DISPATCHED,
        ], true);
    }

    /**
     * Field app: salesman may mark delivered only when admin assigned this drop to them.
     */
    public function fieldCanMarkDelivered(?User $user): bool
    {
        if (! $user || ! $this->canDeliver()) {
            return false;
        }

        $delivery = $this->relationLoaded('delivery') ? $this->delivery : $this->delivery()->first();

        return $delivery
            && (int) $delivery->assigned_to === (int) $user->id
            && $delivery->status !== Delivery::STATUS_DELIVERED;
    }

    /** Salesman collects only after the goods are marked delivered. */
    public function canCollectNow(): bool
    {
        return $this->isDelivered();
    }

    public function canApproveNow(): bool
    {
        if ($this->isPendingAudit() && ! $this->advance_required) {
            return true;
        }

        return $this->isAwaitingAdvance() && $this->isAdvancePaid();
    }

    public function canPartnerEdit(): bool
    {
        return $this->isPendingAudit() && ! $this->stock_reserved && ! $this->advance_required;
    }

    public function canDeleteBeforeApproval(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING_AUDIT, self::STATUS_AWAITING_ADVANCE], true)
            && ! $this->stock_reserved
            && ! $this->hasRecordedPayments();
    }

    /** Any pending or verified payment against this order's invoices. */
    public function hasRecordedPayments(): bool
    {
        $invoiceIds = array_values(array_filter([$this->advance_invoice_id, $this->invoice_id]));

        return $invoiceIds !== [] && Payment::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_VERIFIED])
            ->exists();
    }

    public function canApplyDiscount(): bool
    {
        return $this->isPendingAudit()
            && ! $this->stock_reserved
            && ! $this->advance_required
            && ! $this->hasRecordedPayments();
    }

    /**
     * Unpaid invoices already issued for this shop, excluding this order's remaining baki.
     */
    public function previousShopBaki(): float
    {
        $shopBaki = round((float) ($this->shop?->outstanding_balance ?? 0), 2);

        if ($this->invoice_id || $this->isDelivered()) {
            $thisOpen = round((float) $this->settlement()['remaining_due'], 2);

            return max(0, round($shopBaki - $thisOpen, 2));
        }

        return $shopBaki;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isAccessibleBy(User $user): bool
    {
        if ($user->isSuperAdmin() || $user->hasGlobalAccessScope()) {
            return true;
        }

        if ($user->portal === User::PORTAL_SALESMAN) {
            return $this->salesman_id === $user->id;
        }

        if ($user->portal === User::PORTAL_SHOP) {
            return $this->shop?->users()->where('users.id', $user->id)->exists() ?? false;
        }

        if ($this->warehouse_id && $user->accessScopes()
            ->where('scope_type', UserAccessScope::TYPE_WAREHOUSE)
            ->where('scope_id', $this->warehouse_id)
            ->exists()) {
            return true;
        }

        return $this->shop?->isAccessibleBy($user) ?? false;
    }

    /** List query matching isAccessibleBy(). */
    public function scopeVisibleTo(\Illuminate\Database\Eloquent\Builder $query, User $user): \Illuminate\Database\Eloquent\Builder
    {
        if ($user->isSuperAdmin() || $user->hasGlobalAccessScope()) {
            return $query;
        }

        if ($user->portal === User::PORTAL_SALESMAN) {
            return $query->where('salesman_id', $user->id);
        }

        $warehouseIds = $user->accessScopes()->where('scope_type', UserAccessScope::TYPE_WAREHOUSE)->pluck('scope_id')->filter()->all();

        return $query->where(fn ($q) => $q
            ->whereHas('shop', fn ($s) => $s->visibleTo($user))
            ->orWhereIn('orders.warehouse_id', $warehouseIds ?: [0]));
    }
}
