<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Support\DemoData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventorySummaryTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_inventory_shows_value_product_counts_and_images(): void
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $warehouseId = Warehouse::defaultWarehouse()?->id;
        $stock = WarehouseStock::query()
            ->with('product.category')
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->where('qty_on_hand', '>', 0)
            ->firstOrFail();
        $product = $stock->product;
        $product->update(['image_path' => 'products/inventory-cover.jpg']);

        $rows = WarehouseStock::query()
            ->with('product.category')
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->get();
        $value = $rows->sum(fn (WarehouseStock $row) => (int) $row->qty_on_hand * (float) $row->product?->inventoryUnitCost());
        $products = $rows->pluck('product_id')->unique()->count();
        $categoryName = $product->category?->name;

        $this->actingAs($admin)
            ->get(route('inventory.index'))
            ->assertOk()
            ->assertSee('Total inventory value')
            ->assertSee('Total products')
            ->assertSee(DemoData::taka($value))
            ->assertSee((string) $products)
            ->assertSee($product->name)
            ->assertSee('storage/products/inventory-cover.jpg')
            ->assertSee('product-thumb', false);

        if ($categoryName) {
            $this->actingAs($admin)
                ->get(route('inventory.index'))
                ->assertSee($categoryName);

            $this->actingAs($admin)
                ->get(route('inventory.index', ['category_id' => $product->category_id]))
                ->assertOk()
                ->assertSee($product->name)
                ->assertSee($categoryName);
        }
    }
}
