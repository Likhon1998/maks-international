<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ShopVisit extends Model
{
    public const OUTCOME_IN_PROGRESS = 'in_progress';

    public const OUTCOME_ORDER_TAKEN = 'order_taken';

    public const OUTCOME_NO_ORDER = 'no_order';

    public const OUTCOME_CLOSED = 'closed';

    public const OUTCOME_FOLLOW_UP = 'follow_up';

    /** Check-in opened by mistake — not a real shop visit. */
    public const OUTCOME_CANCELLED = 'cancelled';

    protected $fillable = [
        'salesman_id', 'shop_id', 'checked_in_at', 'checked_out_at',
        'purpose', 'outcome', 'notes', 'photo_path', 'latitude', 'longitude', 'order_id',
    ];

    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesman_id');
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isOpen(): bool
    {
        return $this->checked_out_at === null;
    }

    public function isAssignedRoute(): bool
    {
        return $this->shop_id && (int) $this->shop?->assigned_salesman_id === (int) $this->salesman_id;
    }

    public function needsPhotoEvidence(): bool
    {
        return $this->isAssignedRoute() && ! $this->order_id;
    }

    public function photoUrl(): ?string
    {
        if (! $this->photo_path) {
            return null;
        }

        if (! Storage::disk('public')->exists($this->photo_path)) {
            return null;
        }

        // Relative URL so thumbs work even when APP_URL host differs from the browser.
        return asset('storage/'.$this->photo_path);
    }

    public function outcomeLabel(): string
    {
        return match ($this->outcome) {
            self::OUTCOME_ORDER_TAKEN => 'Order taken',
            self::OUTCOME_NO_ORDER => 'No order',
            self::OUTCOME_CLOSED => 'Shop closed',
            self::OUTCOME_FOLLOW_UP => 'Follow-up needed',
            self::OUTCOME_CANCELLED => 'Opened by mistake',
            default => 'In progress',
        };
    }

    public function countsAsRealVisit(): bool
    {
        return ! in_array($this->outcome, [self::OUTCOME_CANCELLED], true);
    }

    public function isAccessibleBy(User $user): bool
    {
        if ($user->isSuperAdmin() || $user->hasGlobalAccessScope()) {
            return true;
        }

        if ($user->portal === User::PORTAL_SALESMAN) {
            return $this->salesman_id === $user->id;
        }

        return $this->shop?->isAccessibleBy($user) ?? false;
    }
}
