<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Shop extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ON_HOLD = 'on_hold';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'code', 'name', 'owner_name', 'phone', 'email', 'address', 'city',
        'division', 'district', 'thana', 'post_office', 'location',
        'latitude', 'longitude', 'territory_id', 'price_group_id', 'assigned_salesman_id',
        'credit_limit', 'outstanding_balance', 'payment_terms_days',
        'status', 'notes', 'photo_path', 'grade', 'rating', 'created_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'outstanding_balance' => 'decimal:2',
            'rating' => 'decimal:1',
            'approved_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function isFieldAdded(): bool
    {
        return $this->created_by !== null && $this->created_by === $this->assigned_salesman_id;
    }

    public function photoUrl(): ?string
    {
        if (! $this->photo_path) {
            return null;
        }

        if (! Storage::disk('public')->exists($this->photo_path)) {
            return null;
        }

        return asset('storage/'.$this->photo_path);
    }

    public function mapUrl(): ?string
    {
        if ($this->latitude !== null && $this->longitude !== null) {
            return 'https://www.google.com/maps/search/?api=1&query='.$this->latitude.','.$this->longitude;
        }

        $query = trim(implode(', ', array_filter([$this->name, $this->address, $this->city])));

        return $query === '' ? null : 'https://www.google.com/maps/search/?api=1&query='.urlencode($query);
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }

    public function priceGroup(): BelongsTo
    {
        return $this->belongsTo(PriceGroup::class);
    }

    public function assignedSalesman(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_salesman_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function visits(): HasMany
    {
        return $this->hasMany(ShopVisit::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function productReturns(): HasMany
    {
        return $this->hasMany(ProductReturn::class);
    }

    public function availableCredit(): float
    {
        return max(0, (float) $this->credit_limit - (float) $this->outstanding_balance);
    }

    /**
     * Ledger outstanding minus pending আদায় (not yet office-confirmed).
     * Use this for salesman-facing baki so collected money reduces the number immediately.
     */
    public function fieldOutstanding(): float
    {
        $pending = round((float) Payment::query()
            ->where('shop_id', $this->id)
            ->where('status', Payment::STATUS_PENDING)
            ->sum('amount'), 2);

        return max(0, round((float) $this->outstanding_balance - $pending, 2));
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => __('field.shop_status.active'),
            self::STATUS_ON_HOLD => __('field.shop_status.on_hold'),
            self::STATUS_REJECTED => __('field.shop_status.rejected'),
            default => __('field.shop_status.pending'),
        };
    }

    /** List query matching isAccessibleBy(). */
    public function scopeVisibleTo(\Illuminate\Database\Eloquent\Builder $query, User $user): \Illuminate\Database\Eloquent\Builder
    {
        if ($user->isSuperAdmin() || $user->hasGlobalAccessScope()) {
            return $query;
        }

        if ($user->portal === User::PORTAL_SHOP) {
            return $query->whereHas('users', fn ($q) => $q->where('users.id', $user->id));
        }

        $scopes = $user->accessScopes()->get();
        $shopIds = $scopes->where('scope_type', UserAccessScope::TYPE_SHOP)->pluck('scope_id')->filter()->all();
        $territoryIds = $scopes->where('scope_type', UserAccessScope::TYPE_TERRITORY)->pluck('scope_id')->filter()->all();

        return $query->where(fn ($q) => $q->whereIn('shops.id', $shopIds ?: [0])->orWhereIn('shops.territory_id', $territoryIds ?: [0]));
    }

    public function isAccessibleBy(User $user): bool
    {
        if ($user->isSuperAdmin() || $user->hasGlobalAccessScope()) {
            return true;
        }

        if ($user->portal === User::PORTAL_SHOP) {
            return $this->users()->where('users.id', $user->id)->exists();
        }

        return $user->accessScopes()
            ->where(function ($q) {
                $q->where(function ($inner) {
                    $inner->where('scope_type', UserAccessScope::TYPE_SHOP)
                        ->where('scope_id', $this->id);
                })->orWhere(function ($inner) {
                    $inner->where('scope_type', UserAccessScope::TYPE_TERRITORY)
                        ->where('scope_id', $this->territory_id);
                });
            })
            ->exists();
    }
}
