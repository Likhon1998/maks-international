<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductPrice;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProductService
{
    public const MAX_IMAGES = 12;

    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * @param  array<int, UploadedFile>  $images
     */
    public function create(array $data, ?User $actor = null, array $groupPrices = [], array $images = []): Product
    {
        return DB::transaction(function () use ($data, $actor, $groupPrices, $images) {
            $product = Product::query()->create([
                ...collect($data)->only([
                    'name', 'sku', 'barcode', 'category_id', 'brand_id', 'model', 'variant',
                    'description', 'image_path', 'cost_price', 'china_purchase_price', 'shipping_cost', 'landed_cost', 'wholesale_price',
                    'dealer_price', 'distributor_price', 'retail_price', 'minimum_selling_price',
                    'warranty', 'minimum_stock', 'stock_on_hand', 'status', 'is_published',
                ])->all(),
                'created_by' => $actor?->id,
            ]);

            if (! empty($data['image_path']) && $product->images()->doesntExist()) {
                $product->images()->create([
                    'path' => $data['image_path'],
                    'sort_order' => 0,
                    'is_primary' => true,
                ]);
            }

            $this->attachImages($product, $images);
            $this->syncGroupPrices($product, $groupPrices);

            $this->auditLogger->log(
                'products',
                'created',
                "Created product {$product->sku}",
                $product,
                null,
                $product->only(['sku', 'name', 'wholesale_price', 'stock_on_hand', 'image_path']),
                $actor,
            );

            return $product->fresh(['category', 'brand', 'prices', 'images']);
        });
    }

    /**
     * @param  array<int, UploadedFile>  $images
     * @param  list<int>  $removeImageIds
     */
    public function update(
        Product $product,
        array $data,
        ?User $actor = null,
        ?array $groupPrices = null,
        array $images = [],
        array $removeImageIds = [],
        ?int $primaryImageId = null,
    ): Product {
        return DB::transaction(function () use ($product, $data, $actor, $groupPrices, $images, $removeImageIds, $primaryImageId) {
            $old = $product->only(['name', 'wholesale_price', 'status', 'stock_on_hand', 'image_path']);

            $product->update(collect($data)->only([
                'name', 'sku', 'barcode', 'category_id', 'brand_id', 'model', 'variant',
                'description', 'cost_price', 'china_purchase_price', 'shipping_cost', 'landed_cost', 'wholesale_price',
                'dealer_price', 'distributor_price', 'retail_price', 'minimum_selling_price',
                'warranty', 'minimum_stock', 'status', 'is_published',
            ])->all());

            $this->removeImages($product, $removeImageIds);
            $this->attachImages($product, $images);
            $this->setPrimaryImage($product, $primaryImageId);
            $this->syncCoverPath($product);

            if ($groupPrices !== null) {
                $this->syncGroupPrices($product, $groupPrices);
            }

            $this->auditLogger->log(
                'products',
                'updated',
                "Updated product {$product->sku}",
                $product,
                $old,
                $product->fresh()->only(['name', 'wholesale_price', 'status', 'stock_on_hand', 'image_path']),
                $actor,
            );

            return $product->fresh(['category', 'brand', 'prices', 'images']);
        });
    }

    /**
     * Move the product's total on-hand to $total through the inventory ledger, adjusting the
     * default warehouse by the difference.
     */
    public function setTotalStock(Product $product, int $total, ?User $actor = null): void
    {
        $warehouse = Warehouse::defaultWarehouse();
        if (! $warehouse) {
            throw ValidationException::withMessages(['stock_on_hand' => 'Configure a warehouse before setting stock.']);
        }

        $inventory = app(InventoryService::class);
        $delta = $total - (int) $product->stock_on_hand;
        if ($delta === 0) {
            return;
        }

        $current = (int) $inventory->stockFor($warehouse, $product)->qty_on_hand;
        if ($current + $delta < 0) {
            throw ValidationException::withMessages([
                'stock_on_hand' => "Only {$current} unit(s) are in {$warehouse->code}; move stock with Inventory → Transfer or Adjust instead.",
            ]);
        }

        $inventory->adjust($warehouse, $product, $current + $delta, $actor, 'Set from product form');
    }

    public function syncGroupPrices(Product $product, array $groupPrices): void
    {
        foreach ($groupPrices as $priceGroupId => $wholesale) {
            if ($wholesale === null || $wholesale === '') {
                ProductPrice::query()
                    ->where('product_id', $product->id)
                    ->where('price_group_id', $priceGroupId)
                    ->delete();

                continue;
            }

            ProductPrice::query()->updateOrCreate(
                [
                    'product_id' => $product->id,
                    'price_group_id' => $priceGroupId,
                ],
                [
                    'wholesale_price' => $wholesale,
                    'minimum_selling_price' => $product->minimum_selling_price,
                ],
            );
        }
    }

    /**
     * @param  array<int, UploadedFile|null>  $files
     */
    private function attachImages(Product $product, array $files): void
    {
        $files = array_values(array_filter($files, fn ($file) => $file instanceof UploadedFile));
        if ($files === []) {
            return;
        }

        $existing = $product->images()->count();
        if ($existing + count($files) > self::MAX_IMAGES) {
            throw ValidationException::withMessages([
                'images' => 'A product can have at most '.self::MAX_IMAGES.' pictures.',
            ]);
        }

        $sort = (int) $product->images()->max('sort_order');
        $hasPrimary = $product->images()->where('is_primary', true)->exists();

        foreach ($files as $i => $file) {
            $path = $file->store('products', 'public');
            $isPrimary = ! $hasPrimary && $i === 0;
            $product->images()->create([
                'path' => $path,
                'sort_order' => ++$sort,
                'is_primary' => $isPrimary,
            ]);
            if ($isPrimary) {
                $hasPrimary = true;
            }
        }

        $this->syncCoverPath($product);
    }

    /**
     * @param  list<int|string>  $ids
     */
    private function removeImages(Product $product, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return;
        }

        $product->images()
            ->whereIn('id', $ids)
            ->get()
            ->each(function (ProductImage $image) {
                if ($image->path && ! str_starts_with($image->path, 'http')) {
                    Storage::disk('public')->delete($image->path);
                }
                $image->delete();
            });
    }

    private function setPrimaryImage(Product $product, ?int $imageId): void
    {
        if (! $imageId) {
            return;
        }

        $image = ProductImage::query()
            ->where('product_id', $product->id)
            ->whereKey($imageId)
            ->first();
        if (! $image) {
            return;
        }

        ProductImage::query()->where('product_id', $product->id)->update(['is_primary' => false]);
        ProductImage::query()->whereKey($image->id)->update(['is_primary' => true]);
    }

    private function syncCoverPath(Product $product): void
    {
        $cover = $product->images()
            ->reorder()
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        $product->update(['image_path' => $cover?->path]);
    }
}
