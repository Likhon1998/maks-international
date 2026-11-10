<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryLedger extends Model
{
    public const TYPE_RECEIVE = 'receive';

    public const TYPE_ADJUST = 'adjust';

    public const TYPE_RESERVE = 'reserve';

    public const TYPE_RELEASE = 'release';

    public const TYPE_PICK = 'pick';

    public const TYPE_TRANSFER_IN = 'transfer_in';

    public const TYPE_TRANSFER_OUT = 'transfer_out';

    protected $fillable = [
        'warehouse_id', 'product_id', 'order_id', 'type', 'qty_delta',
        'qty_on_hand_after', 'qty_reserved_after', 'reference', 'notes', 'created_by',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function affectsOnHand(): bool
    {
        return in_array($this->type, [
            self::TYPE_RECEIVE,
            self::TYPE_PICK,
            self::TYPE_ADJUST,
            self::TYPE_TRANSFER_IN,
            self::TYPE_TRANSFER_OUT,
        ], true);
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_RECEIVE => 'Stock in',
            self::TYPE_PICK => 'Stock out (sale)',
            self::TYPE_ADJUST => 'Stock adjustment',
            self::TYPE_TRANSFER_IN => 'Transfer in',
            self::TYPE_TRANSFER_OUT => 'Transfer out',
            self::TYPE_RESERVE => 'Reserved',
            self::TYPE_RELEASE => 'Reservation released',
            default => ucfirst(str_replace('_', ' ', (string) $this->type)),
        };
    }

    public function reasonLabel(): string
    {
        $notes = trim((string) ($this->notes ?? ''));
        if ($notes !== '') {
            return $notes;
        }

        $ref = trim((string) ($this->reference ?? ''));

        return $ref !== '' ? $this->typeLabel().' · '.$ref : $this->typeLabel();
    }

    /**
     * @return 'stock_in'|'stock_out'|'product_return'|'supplier_return'|'damage'|'adjustment'|'other'
     */
    public function movementBucket(): string
    {
        $haystack = strtolower(trim(($this->notes ?? '').' '.($this->reference ?? '')));

        if (preg_match('/damage|damaged|lost|write[\s-]?off|spoil/', $haystack)) {
            return 'damage';
        }
        if (preg_match('/supplier return|return to supplier|vendor return|\brtv\b/', $haystack)) {
            return 'supplier_return';
        }
        if (
            $this->type === self::TYPE_RECEIVE
            && (preg_match('/\breturn\b/', $haystack) || preg_match('/^ret[-]/i', (string) $this->reference))
        ) {
            return 'product_return';
        }

        return match ($this->type) {
            self::TYPE_RECEIVE, self::TYPE_TRANSFER_IN => 'stock_in',
            self::TYPE_PICK, self::TYPE_TRANSFER_OUT => 'stock_out',
            self::TYPE_ADJUST => 'adjustment',
            default => 'other',
        };
    }
}
