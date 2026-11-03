<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesmanOrderPriceTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_salesman_can_sell_below_list_and_admin_sees_both_prices(): void
    {
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $shop = Shop::query()->where('status', Shop::STATUS_ACTIVE)->firstOrFail();
        $shop->update(['assigned_salesman_id' => $salesman->id]);
        $product = Product::query()->where('status', Product::STATUS_ACTIVE)->where('is_published', true)->firstOrFail();
        $list = (float) $product->priceForGroup($shop->priceGroup);
        $sold = round($list + 25, 2);

        $visit = ShopVisit::query()->create([
            'shop_id' => $shop->id,
            'salesman_id' => $salesman->id,
            'checked_in_at' => now(),
        ]);

        $this->actingAs($salesman)
            ->post(route('field.visit.order', $visit), [
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_price' => $sold,
                ]],
                'notes' => 'Owner asked for a better price on this line.',
            ])
            ->assertSessionHasNoErrors();

        $order = Order::query()->where('visit_id', $visit->id)->firstOrFail();
        $item = $order->items()->firstOrFail();

        $this->assertSame($salesman->id, $order->salesman_id);
        $this->assertSame($shop->id, $order->shop_id);
        $this->assertEqualsWithDelta($list, (float) $item->original_unit_price, 0.01);
        $this->assertEqualsWithDelta($sold, (float) $item->unit_price, 0.01);
        $this->assertEqualsWithDelta($sold * 2, (float) $item->line_total, 0.01);
        $this->assertSame(2, (int) $item->quantity);
        $this->assertNotNull($order->submitted_at);
        $this->assertTrue($item->wasPriceChanged());

        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $this->actingAs($admin)
            ->get(route('orders.index'))
            ->assertOk()
            ->assertSee($order->number)
            ->assertSee($shop->name)
            ->assertSee($salesman->name)
            ->assertSee('Price changed');

        $this->actingAs($admin)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee($shop->name)
            ->assertSee($shop->owner_name ?: $shop->name)
            ->assertSee($salesman->name)
            ->assertSee($product->name)
            ->assertSee('Shopkeeper')
            ->assertSee('Original')
            ->assertSee('Sold at')
            ->assertSee('Changed by salesman');
    }
}
