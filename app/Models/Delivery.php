<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Delivery extends Model
{
    public const STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'number', 'order_id', 'fulfilment_id', 'warehouse_id', 'shop_id',
        'assigned_to', 'status', 'tracking_ref', 'delivery_address', 'notes',
        'dispatched_at', 'received_at', 'received_by', 'delivered_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function fulfilment(): BelongsTo
    {
        return $this->belongsTo(OrderFulfilment::class, 'fulfilment_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryItem::class);
    }

    public function isFullyReceived(): bool
    {
        if ($this->items->isEmpty()) {
            return false;
        }

        return $this->items->every(fn (DeliveryItem $item) => (int) $item->qty_received > 0);
    }

    public function remainingQty(): int
    {
        return (int) $this->items->sum(fn (DeliveryItem $item) => $item->qtyRemaining());
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_OUT_FOR_DELIVERY => 'Out for delivery',
            self::STATUS_DELIVERED => 'Delivered',
            self::STATUS_FAILED => 'Failed',
            default => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }

    /** Field UI tone for status pills. */
    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_OUT_FOR_DELIVERY => 'sky',
            self::STATUS_DELIVERED => 'green',
            self::STATUS_FAILED => 'red',
            default => 'gray',
        };
    }
}
