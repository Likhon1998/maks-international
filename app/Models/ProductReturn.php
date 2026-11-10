<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductReturn extends Model
{
    use SoftDeletes;

    protected $table = 'returns';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_RESTOCKED = 'restocked';

    public const STATUS_REJECTED = 'rejected';

    /** @deprecated use STATUS_RESTOCKED */
    public const STATUS_COMPLETED = 'restocked';

    public const REASON_WARRANTY = 'warranty';

    public const REASON_DEFECT = 'defect';

    public const REASON_WRONG_ITEM = 'wrong_item';

    public const REASON_NOT_ACCEPTED = 'not_accepted';

    public const REASON_OTHER = 'other';

    public const REASON_DAMAGED = 'damaged';

    public const REPAIR_NONE = 'none';

    public const REPAIR_PENDING = 'pending';

    public const REPAIR_IN_PROGRESS = 'in_repair';

    public const REPAIR_DONE = 'repaired';

    public const CONDITION_GOOD = 'good';

    public const CONDITION_DAMAGED = 'damaged';

    public const CONDITION_REPAIR = 'repair';

    protected $fillable = [
        'number', 'shop_id', 'order_id', 'delivery_id', 'invoice_id', 'status', 'reason_type',
        'reason', 'total', 'restock', 'credit_issued', 'replaced', 'repair_status', 'inventory_condition',
        'refund_amount', 'applied_to_invoice', 'approved_at', 'approved_by',
        'restocked_at', 'restocked_by', 'resolution_notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'restock' => 'boolean',
            'credit_issued' => 'boolean',
            'replaced' => 'boolean',
            'refund_amount' => 'decimal:2',
            'applied_to_invoice' => 'decimal:2',
            'approved_at' => 'datetime',
            'restocked_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function restocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restocked_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnItem::class, 'return_id');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Pending Return',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_RESTOCKED => 'Added to Inventory',
            self::STATUS_REJECTED => 'Rejected',
            default => ucfirst((string) $this->status),
        };
    }

    public function reasonTypeLabel(): string
    {
        return match ($this->reason_type) {
            self::REASON_WARRANTY => 'Warranty',
            self::REASON_DEFECT => 'Defective',
            self::REASON_DAMAGED => 'Damaged',
            self::REASON_WRONG_ITEM => 'Wrong product',
            self::REASON_NOT_ACCEPTED => 'Customer return',
            default => 'Other',
        };
    }

    public function handlingStatusLabel(): string
    {
        if ($this->replaced) {
            return 'Replaced';
        }
        if ($this->repair_status === self::REPAIR_DONE) {
            return 'Repaired';
        }
        if ($this->credit_issued || (float) $this->refund_amount > 0) {
            return match ($this->status) {
                self::STATUS_PENDING => 'Pending',
                self::STATUS_REJECTED => 'Rejected',
                default => 'Refunded',
            };
        }

        return match ($this->status) {
            self::STATUS_PENDING => 'Pending',
            self::STATUS_APPROVED, self::STATUS_RESTOCKED => 'Approved',
            self::STATUS_REJECTED => 'Rejected',
            default => $this->statusLabel(),
        };
    }

    public function repairStatusLabel(): string
    {
        return match ($this->repair_status) {
            self::REPAIR_PENDING => 'Pending repair',
            self::REPAIR_IN_PROGRESS => 'In repair',
            self::REPAIR_DONE => 'Repaired',
            default => 'Not in repair',
        };
    }

    public function inventoryConditionLabel(): string
    {
        if ($this->status !== self::STATUS_RESTOCKED && ! $this->inventory_condition) {
            return 'Not added';
        }

        return match ($this->inventory_condition) {
            self::CONDITION_GOOD => 'Good',
            self::CONDITION_DAMAGED => 'Damaged',
            self::CONDITION_REPAIR => 'Repair',
            default => $this->status === self::STATUS_RESTOCKED ? 'Good' : 'Not added',
        };
    }

    public function isWarrantyClaim(): bool
    {
        return $this->reason_type === self::REASON_WARRANTY;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRestocked(): bool
    {
        return $this->status === self::STATUS_RESTOCKED;
    }

    public function totalQuantity(): int
    {
        return (int) $this->items->sum('quantity');
    }

    public function productSummary(int $limit = 3): string
    {
        $names = $this->items->take($limit)->map(fn ($item) => $item->product_name.' ×'.$item->quantity);

        if ($this->items->count() > $limit) {
            $names->push('+'.($this->items->count() - $limit).' more');
        }

        return $names->implode(', ');
    }
}
