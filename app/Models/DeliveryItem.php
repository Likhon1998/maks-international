<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryItem extends Model
{
    protected $fillable = [
        'delivery_id', 'order_item_id', 'product_id', 'product_name', 'product_sku',
        'qty_ordered', 'qty_received', 'qty_delivered', 'qty_returned',
        'received_at', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function qtyRemaining(): int
    {
        return max(0, (int) $this->qty_received - (int) $this->qty_delivered - (int) $this->qty_returned);
    }

    public function isReceived(): bool
    {
        return (int) $this->qty_received > 0;
    }
}
