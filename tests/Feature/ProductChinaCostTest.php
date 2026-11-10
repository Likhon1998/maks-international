<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductChinaCostTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_add_product_calculates_total_cost_from_shipping_and_china_price(): void
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('products.create'))
            ->assertOk()
            ->assertSee('China purchase price')
            ->assertSee('Shipping cost')
            ->assertSee('Total cost')
            ->assertSee('Calculate total cost automatically');

        $this->actingAs($admin)
            ->post(route('products.store'), [
                'name' => 'China Cost Probe',
                'sku' => 'CN-COST-1',
                'wholesale_price' => 1800,
                'status' => 'active',
                'china_purchase_price' => 1200.50,
                'shipping_cost' => 175.25,
                'auto_total_cost' => 1,
                'cost_price' => 1,
            ])
            ->assertSessionHasNoErrors();

        $product = Product::query()->where('sku', 'CN-COST-1')->firstOrFail();
        $this->assertEqualsWithDelta(1200.50, (float) $product->china_purchase_price, 0.01);
        $this->assertEqualsWithDelta(175.25, (float) $product->shipping_cost, 0.01);
        $this->assertEqualsWithDelta(1375.75, (float) $product->cost_price, 0.01);
        $this->assertEqualsWithDelta(1375.75, $product->purchaseTotalCost(), 0.01);
        $this->assertEqualsWithDelta(1375.75, $product->inventoryUnitCost(), 0.01);
    }

    public function test_manual_total_cost_is_kept_when_auto_calculate_is_off(): void
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $product = Product::query()->firstOrFail();

        $this->actingAs($admin)
            ->put(route('products.update', $product), [
                'name' => $product->name,
                'sku' => $product->sku,
                'wholesale_price' => $product->wholesale_price,
                'status' => $product->status,
                'china_purchase_price' => 100,
                'shipping_cost' => 40,
                'auto_total_cost' => 0,
                'cost_price' => 999,
            ])
            ->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertEqualsWithDelta(100, (float) $product->china_purchase_price, 0.01);
        $this->assertEqualsWithDelta(40, (float) $product->shipping_cost, 0.01);
        $this->assertEqualsWithDelta(999, (float) $product->cost_price, 0.01);
    }
}
