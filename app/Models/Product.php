<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_DISCONTINUED = 'discontinued';

    protected $fillable = [
        'name', 'sku', 'barcode', 'category_id', 'brand_id', 'model', 'variant',
        'description', 'image_path', 'cost_price', 'china_purchase_price', 'shipping_cost', 'landed_cost', 'wholesale_price',
        'dealer_price', 'distributor_price', 'retail_price', 'minimum_selling_price',
        'warranty', 'minimum_stock', 'stock_on_hand', 'reserved_stock',
        'status', 'is_published', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'china_purchase_price' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'landed_cost' => 'decimal:2',
            'wholesale_price' => 'decimal:2',
            'dealer_price' => 'decimal:2',
            'distributor_price' => 'decimal:2',
            'retail_price' => 'decimal:2',
            'minimum_selling_price' => 'decimal:2',
            'is_published' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(ProductOffer::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function activeOffer(): ?ProductOffer
    {
        if ($this->relationLoaded('offers')) {
            return $this->offers->first(fn (ProductOffer $offer) => $offer->isCurrentlyActive());
        }

        return $this->offers()->currentlyActive()->orderByDesc('id')->first();
    }

    public function publicOrderUrl(): string
    {
        return app(\App\Support\SiteContent::class)->productOrderUrl($this);
    }

    public function publicPrice(): ?float
    {
        $retail = (float) $this->retail_price;
        if ($retail > 0) {
            return $retail;
        }

        return null;
    }

    public function purchaseTotalCost(): float
    {
        return round((float) $this->shipping_cost + (float) $this->china_purchase_price, 2);
    }

    public function inventoryUnitCost(): float
    {
        $landed = (float) $this->landed_cost;
        if ($landed > 0) {
            return $landed;
        }

        return (float) $this->cost_price;
    }

    public function availableStock(): int
    {
        return max(0, (int) $this->stock_on_hand - (int) $this->reserved_stock);
    }

    public function imageUrl(): ?string
    {
        $urls = $this->imageUrls();

        return $urls[0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function imageUrls(): array
    {
        $images = $this->relationLoaded('images')
            ? $this->images
            : $this->images()->get();

        if ($images->isNotEmpty()) {
            return $images->map(fn (ProductImage $image) => $image->url())->values()->all();
        }

        if (! $this->image_path) {
            return [];
        }

        if (str_starts_with($this->image_path, 'http://') || str_starts_with($this->image_path, 'https://')) {
            return [$this->image_path];
        }

        return [asset('storage/'.$this->image_path)];
    }

    public function priceForGroup(?PriceGroup $group = null): float
    {
        $offer = $this->activeOffer();
        if ($offer && (float) $offer->offer_price > 0) {
            return (float) $offer->offer_price;
        }

        if ($group) {
            $override = $this->prices->firstWhere('price_group_id', $group->id);

            if ($override) {
                if ($override->promotional_price
                    && (! $override->promo_starts_at || $override->promo_starts_at->isPast())
                    && (! $override->promo_ends_at || $override->promo_ends_at->isFuture())
                ) {
                    return (float) $override->promotional_price;
                }

                return (float) $override->wholesale_price;
            }
        }

        return (float) $this->wholesale_price;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_INACTIVE => 'Inactive',
            self::STATUS_DISCONTINUED => 'Discontinued',
            default => $this->availableStock() <= 0
                ? 'Out of Stock'
                : ($this->availableStock() <= $this->minimum_stock ? 'Low Stock' : 'In Stock'),
        };
    }
}
