<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderFulfilment;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\Shop;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\DeliveryStockService;
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
            'assigned_to' => $salesman->id,
            'assigned_at' => now(),
            'status' => Delivery::STATUS_OUT_FOR_DELIVERY,
            'dispatched_at' => now(),
            'delivery_address' => $shop->address ?: 'Dhaka',
        ]);

        $this->actingAs($salesman)
            ->get(route('field.deliveries'))
            ->assertOk()
            ->assertSee('Search shop, delivery, order, phone')
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

        $this->actingAs($salesman)
            ->get(route('field.deliveries', ['q' => 'DEL-FIELD-1']))
            ->assertOk()
            ->assertSee('DEL-FIELD-1')
            ->assertSee($shop->name);

        $this->actingAs($salesman)
            ->get(route('field.deliveries', ['q' => 'no-such-delivery-xyz']))
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
        $this->assertSame(Delivery::STATUS_PENDING, $delivery->fresh()->status);

        $salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $this->post(route('deliveries.assign', $delivery), [
            'assigned_to' => $salesman->id,
            'mark_out' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame(Delivery::STATUS_OUT_FOR_DELIVERY, $delivery->fresh()->status);

        $this->post(route('deliveries.deliver', $delivery))
            ->assertSessionHasNoErrors();
        $this->assertSame(Delivery::STATUS_DELIVERED, $delivery->fresh()->status);
        $this->assertSame(Order::STATUS_DELIVERED, $order->fresh()->status);
    }

    public function test_zero_receive_still_unlocks_deliver_step_without_restock_loop(): void
    {
        [$salesman, $delivery, $product] = $this->prepareAssignedDelivery(6);
        $stockBefore = $this->stockOnHand($product);

        $this->actingAs($salesman)
            ->post(route('field.deliveries.receive', $delivery), [
                'items' => $delivery->items->map(fn ($item) => ['id' => $item->id, 'qty' => 0])->all(),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('field.deliveries.show', $delivery));

        $delivery->refresh()->load('items');
        $this->assertTrue($delivery->isFullyReceived());
        $this->assertNotNull($delivery->received_at);
        $this->assertSame(0, (int) $delivery->items->first()->qty_received);
        $this->assertNotNull($delivery->items->first()->received_at);
        // All 6 ordered units were "not loaded" and returned to warehouse.
        $this->assertEquals($stockBefore + 6, $this->stockOnHand($product));

        $this->actingAs($salesman)
            ->get(route('field.deliveries.show', $delivery))
            ->assertOk()
            ->assertSee('2. Delivered to shop')
            ->assertDontSee('Submit leftover stock')
            ->assertDontSee('Save received quantities');

        // Second receive must be blocked (no double restock).
        $this->actingAs($salesman)
            ->post(route('field.deliveries.receive', $delivery), [
                'items' => $delivery->items->map(fn ($item) => ['id' => $item->id, 'qty' => 0])->all(),
            ])
            ->assertSessionHasErrors('delivery');
        $this->assertEquals($stockBefore + 6, $this->stockOnHand($product));
    }

    public function test_return_form_is_blocked_until_delivery_is_complete(): void
    {
        [$salesman, $delivery] = $this->prepareAssignedDelivery(4);

        $this->actingAs($salesman)
            ->post(route('field.deliveries.receive', $delivery), [
                'items' => $delivery->items->map(fn ($item) => ['id' => $item->id, 'qty' => 4])->all(),
            ])
            ->assertSessionHasNoErrors();

        $delivery->refresh()->load('items');

        $this->actingAs($salesman)
            ->get(route('field.deliveries.show', $delivery))
            ->assertOk()
            ->assertSee('Save & complete delivery')
            ->assertDontSee('Submit leftover stock');

        $this->actingAs($salesman)
            ->post(route('field.deliveries.return', $delivery), [
                'items' => $delivery->items->map(fn ($item) => ['id' => $item->id, 'qty' => 1])->all(),
            ])
            ->assertSessionHasErrors('delivery');
    }

    public function test_complete_delivery_auto_returns_shortfall_and_clears_leftover(): void
    {
        [$salesman, $delivery] = $this->prepareAssignedDelivery(5);

        $this->actingAs($salesman)->post(route('field.deliveries.receive', $delivery), [
            'items' => $delivery->items->map(fn ($item) => ['id' => $item->id, 'qty' => 5])->all(),
        ]);
        $delivery->refresh()->load('items');

        $this->actingAs($salesman)
            ->post(route('field.deliveries.quantities', $delivery), [
                'complete' => 1,
                'items' => $delivery->items->map(fn ($item) => ['id' => $item->id, 'qty' => 3])->all(),
            ])
            ->assertSessionHasNoErrors();

        $delivery->refresh()->load('items');
        $this->assertSame(Delivery::STATUS_DELIVERED, $delivery->status);
        $this->assertSame(0, $delivery->remainingQty());
        $this->assertNotNull(ProductReturn::query()->where('delivery_id', $delivery->id)->first());

        $this->actingAs($salesman)
            ->get(route('field.deliveries.show', $delivery))
            ->assertOk()
            ->assertSee('Delivery complete')
            ->assertDontSee('Submit leftover stock');
    }

    /**
     * @return array{0:User,1:Delivery,2:Product}
     */
    private function prepareAssignedDelivery(int $qty): array
    {
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $shop = Shop::query()->where('status', Shop::STATUS_ACTIVE)->firstOrFail();
        $shop->update(['assigned_salesman_id' => $salesman->id]);
        $product = Product::query()->where('status', Product::STATUS_ACTIVE)->where('is_published', true)->firstOrFail();
        $warehouse = Warehouse::query()->firstOrFail();

        WarehouseStock::query()->updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
            ['qty_on_hand' => max(200, $qty * 20), 'qty_reserved' => 0]
        );
        $product->forceFill(['stock_on_hand' => max(200, $qty * 20), 'reserved_stock' => 0])->save();

        $unit = (float) $product->wholesale_price;
        $order = Order::query()->create([
            'number' => 'ORD-FIELD-FLOW-'.$qty.'-'.uniqid(),
            'shop_id' => $shop->id,
            'warehouse_id' => $warehouse->id,
            'salesman_id' => $salesman->id,
            'source' => Order::SOURCE_SALESMAN,
            'status' => Order::STATUS_DISPATCHED,
            'subtotal' => $unit * $qty,
            'discount_total' => 0,
            'total' => $unit * $qty,
            'item_count' => $qty,
            'submitted_at' => now(),
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'quantity' => $qty,
            'unit_price' => $unit,
            'line_total' => round($unit * $qty, 2),
        ]);

        $fulfilment = OrderFulfilment::query()->create([
            'order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'status' => OrderFulfilment::STATUS_DISPATCHED,
            'dispatched_at' => now(),
        ]);

        $delivery = Delivery::query()->create([
            'number' => 'DEL-FIELD-FLOW-'.$qty.'-'.uniqid(),
            'order_id' => $order->id,
            'fulfilment_id' => $fulfilment->id,
            'warehouse_id' => $warehouse->id,
            'shop_id' => $shop->id,
            'assigned_to' => $salesman->id,
            'assigned_at' => now(),
            'status' => Delivery::STATUS_ASSIGNED,
            'dispatched_at' => now(),
            'delivery_address' => $shop->address ?: 'Dhaka',
        ]);

        app(DeliveryStockService::class)->seedItems($delivery->fresh());

        return [$salesman, $delivery->fresh(['items', 'order']), $product->fresh()];
    }

    private function stockOnHand(Product $product): int
    {
        $warehouse = Warehouse::defaultWarehouse() ?: Warehouse::query()->firstOrFail();

        return (int) WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->value('qty_on_hand');
    }
}
