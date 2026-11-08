<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderFulfilment;
use App\Models\Shop;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\FulfilmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_admin_can_list_delivery_men_and_assign_manually(): void
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $rider = User::where('email', 'karim@field.local')->firstOrFail();
        $other = User::where('email', 'sabrina@field.local')->firstOrFail();

        [$delivery] = $this->makeOpenDelivery(assignedTo: null, status: Delivery::STATUS_PENDING);

        $this->actingAs($admin)
            ->get(route('deliveries.men'))
            ->assertOk()
            ->assertSee($rider->name)
            ->assertSee($other->name);

        $this->actingAs($admin)
            ->post(route('deliveries.assign', $delivery), [
                'assigned_to' => $rider->id,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $delivery->refresh();
        $this->assertSame($rider->id, (int) $delivery->assigned_to);
        $this->assertSame(Delivery::STATUS_ASSIGNED, $delivery->status);
        $this->assertNotNull($delivery->assigned_at);

        $this->actingAs($admin)
            ->post(route('deliveries.assign', $delivery), [
                'assigned_to' => $other->id,
                'mark_out' => 1,
            ])
            ->assertSessionHasNoErrors();

        $delivery->refresh();
        $this->assertSame($other->id, (int) $delivery->assigned_to);
        $this->assertSame(Delivery::STATUS_OUT_FOR_DELIVERY, $delivery->status);
    }

    public function test_dispatch_does_not_auto_assign_from_order_or_shop_salesman(): void
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $shop = Shop::query()->where('status', Shop::STATUS_ACTIVE)->firstOrFail();
        $shop->update(['assigned_salesman_id' => $salesman->id]);
        $warehouse = Warehouse::query()->firstOrFail();

        $order = Order::query()->create([
            'number' => 'ORD-NO-AUTO',
            'shop_id' => $shop->id,
            'warehouse_id' => $warehouse->id,
            'salesman_id' => $salesman->id,
            'source' => Order::SOURCE_ADMIN,
            'status' => Order::STATUS_PACKED,
            'subtotal' => 1000,
            'discount_total' => 0,
            'total' => 1000,
            'item_count' => 1,
            'submitted_at' => now(),
            'approved_at' => now(),
        ]);

        $fulfilment = OrderFulfilment::query()->create([
            'order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'status' => OrderFulfilment::STATUS_PACKED,
            'packed_at' => now(),
        ]);

        $delivery = app(FulfilmentService::class)->dispatch($fulfilment, $admin, [
            'tracking_ref' => 'MANUAL',
        ]);

        $this->assertNull($delivery->assigned_to);
        $this->assertSame(Delivery::STATUS_PENDING, $delivery->status);

        $this->actingAs($salesman)
            ->get(route('field.deliveries'))
            ->assertOk()
            ->assertDontSee($delivery->number);
    }

    public function test_assigned_delivery_man_sees_shop_and_products_in_field_panel(): void
    {
        $rider = User::where('email', 'karim@field.local')->firstOrFail();
        $other = User::where('email', 'sabrina@field.local')->firstOrFail();
        $shop = Shop::query()->where('status', Shop::STATUS_ACTIVE)->firstOrFail();
        $shop->update([
            'name' => 'Corner Mart Assigned',
            'phone' => '01700001111',
            'address' => '12 Test Road, Dhaka',
            'assigned_salesman_id' => $other->id,
        ]);

        [$delivery, $order] = $this->makeOpenDelivery(
            assignedTo: $rider->id,
            status: Delivery::STATUS_ASSIGNED,
            shop: $shop,
            orderNumber: 'ORD-ASSIGN-1',
            deliveryNumber: 'DEL-ASSIGN-1',
        );

        $product = \App\Models\Product::query()->firstOrFail();
        $order->items()->create([
            'product_id' => $product->id,
            'product_sku' => $product->sku,
            'product_name' => 'Assigned Demo Product',
            'quantity' => 3,
            'unit_price' => 100,
            'line_total' => 300,
        ]);

        $this->actingAs($rider)
            ->get(route('field.deliveries'))
            ->assertOk()
            ->assertSee('DEL-ASSIGN-1')
            ->assertSee('Corner Mart Assigned');

        $this->actingAs($rider)
            ->get(route('field.deliveries.show', $delivery))
            ->assertOk()
            ->assertSee('Corner Mart Assigned')
            ->assertSee('12 Test Road, Dhaka')
            ->assertSee('01700001111')
            ->assertSee('Assigned Demo Product');

        $this->actingAs($other)
            ->get(route('field.deliveries.show', $delivery))
            ->assertForbidden();
    }

    public function test_field_mark_delivered_requires_assignment_to_that_salesman(): void
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $rider = User::where('email', 'karim@field.local')->firstOrFail();
        $other = User::where('email', 'sabrina@field.local')->firstOrFail();

        [$delivery, $order] = $this->makeOpenDelivery(
            assignedTo: $rider->id,
            status: Delivery::STATUS_OUT_FOR_DELIVERY,
            orderNumber: 'ORD-FIELD-GUARD',
            deliveryNumber: 'DEL-FIELD-GUARD',
        );
        $order->update(['salesman_id' => $other->id, 'status' => Order::STATUS_DISPATCHED]);

        $this->actingAs($other)
            ->get(route('field.orders.show', $order))
            ->assertOk()
            ->assertDontSee('Mark delivered')
            ->assertSee('Assigned to');

        $this->actingAs($other)
            ->post(route('field.orders.deliver', $order))
            ->assertSessionHasErrors('order');

        $order->update(['salesman_id' => $rider->id]);
        $this->actingAs($rider)
            ->get(route('field.orders.show', $order))
            ->assertOk()
            ->assertSee('Mark delivered');

        $this->actingAs($admin)
            ->post(route('deliveries.assign', $delivery), ['assigned_to' => $other->id])
            ->assertSessionHasNoErrors();

        $this->actingAs($rider)
            ->post(route('field.orders.deliver', $order))
            ->assertSessionHasErrors('order');
    }

    /**
     * @return array{0: Delivery, 1: Order}
     */
    private function makeOpenDelivery(
        ?int $assignedTo,
        string $status,
        ?Shop $shop = null,
        string $orderNumber = 'ORD-DEL-ASSIGN',
        string $deliveryNumber = 'DEL-OPEN-1',
    ): array {
        $shop ??= Shop::query()->where('status', Shop::STATUS_ACTIVE)->firstOrFail();
        $warehouse = Warehouse::query()->firstOrFail();

        $order = Order::query()->create([
            'number' => $orderNumber,
            'shop_id' => $shop->id,
            'warehouse_id' => $warehouse->id,
            'source' => Order::SOURCE_ADMIN,
            'status' => Order::STATUS_DISPATCHED,
            'subtotal' => 500,
            'discount_total' => 0,
            'total' => 500,
            'item_count' => 1,
            'submitted_at' => now(),
            'approved_at' => now(),
        ]);

        $fulfilment = OrderFulfilment::query()->create([
            'order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'status' => OrderFulfilment::STATUS_DISPATCHED,
            'dispatched_at' => now(),
        ]);

        $delivery = Delivery::query()->create([
            'number' => $deliveryNumber,
            'order_id' => $order->id,
            'fulfilment_id' => $fulfilment->id,
            'warehouse_id' => $warehouse->id,
            'shop_id' => $shop->id,
            'assigned_to' => $assignedTo,
            'assigned_at' => $assignedTo ? now() : null,
            'status' => $status,
            'dispatched_at' => now(),
            'delivery_address' => $shop->address ?: 'Dhaka',
        ]);

        return [$delivery, $order];
    }
}
