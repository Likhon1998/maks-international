<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderFulfilment;
use App\Models\Shop;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldDeliveriesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_salesman_sees_delivery_dashboard_for_own_orders(): void
    {
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $shop = Shop::query()->where('status', Shop::STATUS_ACTIVE)->firstOrFail();
        $shop->update(['assigned_salesman_id' => $salesman->id]);
        $warehouse = Warehouse::query()->firstOrFail();

        $order = Order::query()->create([
            'number' => 'ORD-DEL-1',
            'shop_id' => $shop->id,
            'warehouse_id' => $warehouse->id,
            'salesman_id' => $salesman->id,
            'source' => Order::SOURCE_SALESMAN,
            'status' => Order::STATUS_DISPATCHED,
            'subtotal' => 1000,
            'discount_total' => 0,
            'total' => 1000,
            'item_count' => 1,
            'submitted_at' => now(),
        ]);

        $fulfilment = OrderFulfilment::query()->create([
            'order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'status' => OrderFulfilment::STATUS_DISPATCHED,
            'dispatched_at' => now(),
        ]);

        Delivery::query()->create([
            'number' => 'DEL-FIELD-1',
            'order_id' => $order->id,
            'fulfilment_id' => $fulfilment->id,
            'warehouse_id' => $warehouse->id,
            'shop_id' => $shop->id,
            'status' => Delivery::STATUS_OUT_FOR_DELIVERY,
            'dispatched_at' => now(),
            'delivery_address' => $shop->address ?: 'Dhaka',
        ]);

        $this->actingAs($salesman)
            ->get(route('field.deliveries'))
            ->assertOk()
            ->assertSee('On the way')
            ->assertSee('DEL-FIELD-1')
            ->assertSee($shop->name)
            ->assertSee('ORD-DEL-1');

        $this->actingAs($salesman)
            ->get(route('field.deliveries', ['status' => 'out']))
            ->assertOk()
            ->assertSee('DEL-FIELD-1');

        $this->actingAs($salesman)
            ->get(route('field.deliveries', ['status' => 'delivered']))
            ->assertOk()
            ->assertDontSee('DEL-FIELD-1');
    }

    public function test_office_marks_delivery_failed_then_retries(): void
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $shop = Shop::query()->where('status', Shop::STATUS_ACTIVE)->firstOrFail();
        $warehouse = Warehouse::query()->firstOrFail();

        $order = Order::query()->create([
            'number' => 'ORD-DEL-FAIL',
            'shop_id' => $shop->id,
            'warehouse_id' => $warehouse->id,
            'source' => Order::SOURCE_ADMIN,
            'status' => Order::STATUS_DISPATCHED,
            'subtotal' => 500,
            'discount_total' => 0,
            'total' => 500,
            'item_count' => 1,
            'submitted_at' => now(),
        ]);

        $fulfilment = OrderFulfilment::query()->create([
            'order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'status' => OrderFulfilment::STATUS_DISPATCHED,
            'dispatched_at' => now(),
        ]);

        $delivery = Delivery::query()->create([
            'number' => 'DEL-FAIL-1',
            'order_id' => $order->id,
            'fulfilment_id' => $fulfilment->id,
            'warehouse_id' => $warehouse->id,
            'shop_id' => $shop->id,
            'status' => Delivery::STATUS_OUT_FOR_DELIVERY,
            'dispatched_at' => now(),
            'delivery_address' => 'Dhaka',
        ]);

        $this->actingAs($admin)
            ->post(route('deliveries.fail', $delivery), ['notes' => 'Shop closed'])
            ->assertSessionHasNoErrors();
        $this->assertSame(Delivery::STATUS_FAILED, $delivery->fresh()->status);

        $this->post(route('deliveries.retry', $delivery))
            ->assertSessionHasNoErrors();
        $this->assertSame(Delivery::STATUS_OUT_FOR_DELIVERY, $delivery->fresh()->status);

        $this->post(route('deliveries.deliver', $delivery))
            ->assertSessionHasNoErrors();
        $this->assertSame(Delivery::STATUS_DELIVERED, $delivery->fresh()->status);
        $this->assertSame(Order::STATUS_DELIVERED, $order->fresh()->status);
    }
}
