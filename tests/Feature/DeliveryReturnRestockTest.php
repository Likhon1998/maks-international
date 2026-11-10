<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\Shop;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryReturnRestockTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_partial_shop_accept_creates_pending_return_without_restocking(): void
    {
        [$admin, $salesman, $shop, $product, $delivery] = $this->prepareDispatchedDelivery(10);
        $stockBefore = $this->stockOnHand($product);

        $this->actingAs($salesman)
            ->post(route('field.deliveries.receive', $delivery), [
                'items' => $delivery->items->map(fn ($item) => ['id' => $item->id, 'qty' => 10])->all(),
            ])
            ->assertSessionHasNoErrors();

        $delivery->refresh()->load('items');

        $this->actingAs($salesman)
            ->post(route('field.deliveries.quantities', $delivery), [
                'complete' => 1,
                'items' => $delivery->items->map(fn ($item) => ['id' => $item->id, 'qty' => 5])->all(),
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $return = ProductReturn::query()->where('delivery_id', $delivery->id)->latest('id')->first();
        $this->assertNotNull($return);
        $this->assertSame(ProductReturn::STATUS_PENDING, $return->status);
        $this->assertSame(ProductReturn::REASON_NOT_ACCEPTED, $return->reason_type);
        $this->assertSame(5, (int) $return->items()->sum('quantity'));
        $this->assertSame($shop->id, $return->shop_id);
        $this->assertSame($salesman->id, $return->created_by);

        // Inventory must not increase while pending.
        $this->assertEquals($stockBefore, $this->stockOnHand($product));

        $this->actingAs($admin)
            ->get(route('returns.index', ['status' => 'pending']))
            ->assertOk()
            ->assertSee($return->number)
            ->assertSee($shop->name)
            ->assertSee($product->name)
            ->assertSee('Pending');
    }

    public function test_admin_approve_and_restock_adds_inventory_only_after_approval(): void
    {
        [$admin, $salesman, $shop, $product, $delivery] = $this->prepareDispatchedDelivery(8);

        $this->actingAs($salesman)->post(route('field.deliveries.receive', $delivery), [
            'items' => $delivery->items->map(fn ($item) => ['id' => $item->id, 'qty' => 8])->all(),
        ]);
        $delivery->refresh()->load('items');
        $this->actingAs($salesman)->post(route('field.deliveries.quantities', $delivery), [
            'complete' => 1,
            'items' => $delivery->items->map(fn ($item) => ['id' => $item->id, 'qty' => 3])->all(),
        ]);

        $return = ProductReturn::query()->where('delivery_id', $delivery->id)->latest('id')->firstOrFail();
        $stockBefore = $this->stockOnHand($product);

        $this->actingAs($admin)
            ->post(route('returns.approve', $return), ['restock_now' => 0])
            ->assertSessionHasNoErrors();

        $return->refresh();
        $this->assertSame(ProductReturn::STATUS_APPROVED, $return->status);
        $this->assertTrue($return->credit_issued);
        $this->assertEquals($stockBefore, $this->stockOnHand($product));

        $this->actingAs($admin)
            ->post(route('returns.restock', $return))
            ->assertSessionHasNoErrors();

        $return->refresh();
        $this->assertSame(ProductReturn::STATUS_RESTOCKED, $return->status);
        $this->assertEquals($stockBefore + 5, $this->stockOnHand($product));

        $this->actingAs($admin)
            ->get(route('returns.index', ['status' => 'restocked']))
            ->assertOk()
            ->assertSee('Added to Inventory')
            ->assertSee($return->number);
    }

    public function test_approve_with_restock_now_completes_in_one_step(): void
    {
        [$admin, $salesman, $shop, $product, $delivery] = $this->prepareDispatchedDelivery(6);

        $this->actingAs($salesman)->post(route('field.deliveries.receive', $delivery), [
            'items' => $delivery->items->map(fn ($item) => ['id' => $item->id, 'qty' => 6])->all(),
        ]);
        $delivery->refresh()->load('items');
        $this->actingAs($salesman)->post(route('field.deliveries.quantities', $delivery), [
            'complete' => 1,
            'items' => $delivery->items->map(fn ($item) => ['id' => $item->id, 'qty' => 4])->all(),
        ]);

        $return = ProductReturn::query()->where('delivery_id', $delivery->id)->latest('id')->firstOrFail();
        $stockBefore = $this->stockOnHand($product);

        $this->actingAs($admin)
            ->post(route('returns.approve', $return), ['restock_now' => 1])
            ->assertSessionHasNoErrors();

        $this->assertSame(ProductReturn::STATUS_RESTOCKED, $return->fresh()->status);
        $this->assertEquals($stockBefore + 2, $this->stockOnHand($product));
    }

    public function test_rejected_return_does_not_restock(): void
    {
        [$admin, $salesman, $shop, $product, $delivery] = $this->prepareDispatchedDelivery(4);

        $this->actingAs($salesman)->post(route('field.deliveries.receive', $delivery), [
            'items' => $delivery->items->map(fn ($item) => ['id' => $item->id, 'qty' => 4])->all(),
        ]);
        $delivery->refresh()->load('items');
        $this->actingAs($salesman)->post(route('field.deliveries.quantities', $delivery), [
            'complete' => 1,
            'items' => $delivery->items->map(fn ($item) => ['id' => $item->id, 'qty' => 1])->all(),
        ]);

        $return = ProductReturn::query()->where('delivery_id', $delivery->id)->latest('id')->firstOrFail();
        $stockBefore = $this->stockOnHand($product);

        $this->actingAs($admin)
            ->post(route('returns.reject', $return), ['resolution_notes' => 'Damaged in transit'])
            ->assertSessionHasNoErrors();

        $this->assertSame(ProductReturn::STATUS_REJECTED, $return->fresh()->status);
        $this->assertEquals($stockBefore, $this->stockOnHand($product));
    }

    /**
     * @return array{0:User,1:User,2:Shop,3:Product,4:Delivery}
     */
    private function prepareDispatchedDelivery(int $qty): array
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $shop = Shop::query()->where('status', Shop::STATUS_ACTIVE)->firstOrFail();
        $shop->update(['assigned_salesman_id' => $salesman->id]);
        $product = Product::query()->where('status', Product::STATUS_ACTIVE)->where('is_published', true)->firstOrFail();
        $warehouse = Warehouse::query()->firstOrFail();

        WarehouseStock::query()->updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
            ['qty_on_hand' => max(500, $qty * 20), 'qty_reserved' => 0]
        );
        $product->forceFill(['stock_on_hand' => max(500, $qty * 20), 'reserved_stock' => 0])->save();

        $unit = (float) $product->wholesale_price;
        $order = Order::query()->create([
            'number' => 'ORD-RET-'.$qty.'-'.uniqid(),
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

        $fulfilment = \App\Models\OrderFulfilment::query()->create([
            'order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'status' => \App\Models\OrderFulfilment::STATUS_DISPATCHED,
            'dispatched_at' => now(),
        ]);

        $delivery = Delivery::query()->create([
            'number' => 'DEL-RET-'.$qty.'-'.uniqid(),
            'order_id' => $order->id,
            'fulfilment_id' => $fulfilment->id,
            'warehouse_id' => $warehouse->id,
            'shop_id' => $shop->id,
            'assigned_to' => $salesman->id,
            'status' => Delivery::STATUS_OUT_FOR_DELIVERY,
            'dispatched_at' => now(),
            'delivery_address' => $shop->address ?: 'Dhaka',
            'created_by' => $admin->id,
        ]);

        app(\App\Services\DeliveryStockService::class)->seedItems($delivery->fresh());

        return [$admin, $salesman, $shop, $product, $delivery->fresh(['items', 'order'])];
    }

    private function stockOnHand(Product $product): int
    {
        $warehouse = Warehouse::defaultWarehouse();
        if (! $warehouse) {
            return (int) $product->fresh()->stock_on_hand;
        }

        return (int) WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->value('qty_on_hand');
    }
}
