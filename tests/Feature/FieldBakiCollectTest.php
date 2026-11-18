<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderFulfilment;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopVisit;
use App\Models\User;
use App\Support\DemoData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldBakiCollectTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_salesman_sees_baki_by_shop_with_order_rest_and_can_collect(): void
    {
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $shop = Shop::query()->where('status', Shop::STATUS_ACTIVE)->firstOrFail();
        $shop->update(['assigned_salesman_id' => $salesman->id]);
        $product = Product::query()
            ->where('status', Product::STATUS_ACTIVE)
            ->where('is_published', true)
            ->firstOrFail();

        ShopVisit::query()
            ->where('salesman_id', $salesman->id)
            ->whereNull('checked_out_at')
            ->update(['checked_out_at' => now()]);

        $visit = ShopVisit::query()->create([
            'shop_id' => $shop->id,
            'salesman_id' => $salesman->id,
            'checked_in_at' => now(),
        ]);

        $unit = (float) $product->priceForGroup($shop->priceGroup);

        $this->actingAs($salesman)
            ->post(route('field.visit.order', $visit), [
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => $unit,
                ]],
                'paid_now' => 0,
                'notes' => 'Will collect baki after delivery from dues screen.',
            ])
            ->assertSessionHasNoErrors();

        $order = Order::query()->where('visit_id', $visit->id)->firstOrFail();
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $this->actingAs($admin)->post(route('orders.invoice', $order))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('orders.approve', $order), ['credit_override' => 1])->assertSessionHasNoErrors();
        $this->dispatchAssignedTo($order->fresh(), $salesman, $admin);

        $this->actingAs($salesman)
            ->post(route('field.orders.deliver', $order))
            ->assertSessionHasNoErrors();

        $order->refresh();
        $due = round($order->collectibleBalance(), 2);
        $this->assertGreaterThan(0.009, $due);
        $shop->refresh();

        $home = $this->actingAs($salesman)->get(route('field.dashboard'))->assertOk();
        $home->assertSee('Collect baki', false);
        $home->assertSee(route('field.dues', absolute: false), false);

        $dues = $this->actingAs($salesman)->get(route('field.dues'))->assertOk();
        $dues->assertSee('Collect baki', false);
        $dues->assertSee($shop->name);
        $dues->assertSee(DemoData::taka((float) $shop->outstanding_balance), false);

        $detail = $this->actingAs($salesman)->get(route('field.dues.shop', $shop))->assertOk();
        $detail->assertSee('Why this baki', false);
        $detail->assertSee($order->number);
        $detail->assertSee(DemoData::taka($due), false);
        $detail->assertSee('name="amount"', false);
        $detail->assertSee('redirect', false);

        $half = round($due / 2, 2);
        $this->actingAs($salesman)
            ->post(route('field.orders.collect', $order), [
                'amount' => $half,
                'method' => Payment::METHOD_CASH,
                'redirect' => 'dues',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('field.dues.shop', $shop));

        $this->assertTrue(
            Payment::query()
                ->where('shop_id', $shop->id)
                ->where('created_by', $salesman->id)
                ->where('amount', $half)
                ->where('status', Payment::STATUS_PENDING)
                ->exists()
        );
    }

    public function test_salesman_cannot_open_dues_for_unrelated_shop(): void
    {
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $shop = Shop::query()->create([
            'name' => 'Unrelated Baki Shop',
            'code' => 'UBS-'.uniqid(),
            'owner_name' => 'Someone Else',
            'phone' => '01700000099',
            'status' => Shop::STATUS_ACTIVE,
            'outstanding_balance' => 5000,
            'assigned_salesman_id' => null,
        ]);

        $this->actingAs($salesman)
            ->get(route('field.dues.shop', $shop))
            ->assertForbidden();
    }

    private function dispatchAssignedTo(Order $order, User $salesman, User $admin): void
    {
        $fulfilment = OrderFulfilment::query()->where('order_id', $order->id)->firstOrFail();

        $this->actingAs($admin);
        $this->post(route('fulfilment.start-pick', $fulfilment))->assertSessionHasNoErrors();
        $this->post(route('fulfilment.complete-pick', $fulfilment), ['warehouse_notes' => 'ok'])->assertSessionHasNoErrors();
        $this->post(route('fulfilment.pack', $fulfilment))->assertSessionHasNoErrors();
        $this->post(route('fulfilment.dispatch', $fulfilment), [
            'assigned_to' => $salesman->id,
            'tracking_ref' => 'BAKI-'.$order->id,
        ])->assertSessionHasNoErrors();
    }
}
