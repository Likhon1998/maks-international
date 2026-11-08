<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderFulfilment;
use App\Models\Payment;
use App\Models\Shop;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopPayAtStoreTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_owner_and_salesman_can_pay_before_delivery(): void
    {
        $shopUser = User::where('email', 'techzone@partner.local')->firstOrFail();
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $shop = $shopUser->primaryShop();

        $order = Order::query()->create([
            'number' => 'ORD-PAY-EARLY',
            'shop_id' => $shop->id,
            'salesman_id' => $salesman->id,
            'source' => Order::SOURCE_SALESMAN,
            'status' => Order::STATUS_PENDING_AUDIT,
            'subtotal' => 700,
            'discount_total' => 0,
            'total' => 700,
            'item_count' => 1,
            'submitted_at' => now(),
        ]);

        $this->actingAs($shopUser)
            ->post(route('portal.orders.pay', $order), [
                'amount' => 300,
                'method' => Payment::METHOD_CASH,
            ])
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertNotNull($order->invoice_id);
        $this->assertSame(Order::STATUS_PENDING_AUDIT, $order->status);
        $this->assertTrue(Payment::query()->where('created_by', $shopUser->id)->exists());

        $this->actingAs($salesman)
            ->post(route('field.orders.collect', $order), [
                'amount' => 400,
                'method' => Payment::METHOD_CASH,
            ])
            ->assertSessionHasErrors('amount');

        $this->assertSame(Order::STATUS_PENDING_AUDIT, $order->fresh()->status);
    }

    public function test_van_collects_cash_when_goods_arrive(): void
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $shopUser = User::where('email', 'techzone@partner.local')->firstOrFail();
        $shop = $shopUser->primaryShop();
        $owedBefore = (float) $shop->outstanding_balance;

        ['order' => $order, 'delivery' => $delivery] = $this->dispatchedOrder($shop, null, 800);

        $this->actingAs($admin)
            ->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->assertSee('Goods at the shop')
            ->assertSee('Owner paid now');

        $this->post(route('deliveries.deliver', $delivery), [
            'collected_now' => 800,
            'method' => Payment::METHOD_CASH,
            'notes' => 'Owner paid on drop',
        ])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(Order::STATUS_DELIVERED, $order->status);
        $this->assertNotNull($order->invoice_id);
        $this->assertTrue($order->settlement()['invoice_issued']);
        $this->assertFalse($order->settlement()['collectible']);
        $this->assertTrue($order->settlement()['fully_settled']);

        $invoice = Invoice::query()->findOrFail($order->invoice_id);
        $this->assertEqualsWithDelta(0, (float) $invoice->balance, 0.01);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);

        $payment = Payment::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(Payment::STATUS_VERIFIED, $payment->status);
        $this->assertEqualsWithDelta(800, (float) $payment->amount, 0.01);

        $this->assertEqualsWithDelta($owedBefore, (float) $shop->fresh()->outstanding_balance, 0.05);
    }

    public function test_owner_pays_bill_from_portal_after_delivery(): void
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $shopUser = User::where('email', 'techzone@partner.local')->firstOrFail();
        $shop = $shopUser->primaryShop();
        $owedBefore = (float) $shop->outstanding_balance;

        ['order' => $order, 'delivery' => $delivery] = $this->dispatchedOrder($shop, null, 650);

        $this->actingAs($admin)
            ->post(route('deliveries.deliver', $delivery))
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertTrue($order->settlement()['collectible']);

        $this->actingAs($shopUser)
            ->get(route('portal.orders.show', $order))
            ->assertOk()
            ->assertSee('Pay this bill')
            ->assertSee('Full baki')
            ->assertSee('Pay now');

        $this->post(route('portal.orders.pay', $order), [
            'amount' => 650,
            'method' => Payment::METHOD_CASH,
        ])->assertSessionHasNoErrors();

        $payment = Payment::query()->where('invoice_id', $order->invoice_id)->firstOrFail();
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);

        $this->actingAs($shopUser)
            ->get(route('portal.orders.show', $order))
            ->assertOk()
            ->assertSee('waiting for office confirmation')
            ->assertDontSee('Pay this bill');

        $this->actingAs($admin)
            ->post(route('payments.verify', $payment))
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertTrue($order->settlement()['fully_settled']);
        $this->assertEqualsWithDelta($owedBefore, (float) $shop->fresh()->outstanding_balance, 0.05);
    }

    public function test_salesman_collects_remaining_after_delivery(): void
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $shop = Shop::query()->where('status', Shop::STATUS_ACTIVE)->firstOrFail();

        ['order' => $order, 'delivery' => $delivery] = $this->dispatchedOrder($shop, $salesman, 420);

        $this->actingAs($admin)
            ->post(route('deliveries.deliver', $delivery))
            ->assertSessionHasNoErrors();

        $this->actingAs($salesman)
            ->get(route('field.orders.show', $order))
            ->assertOk()
            ->assertSee('Payment at shop')
            ->assertSee('Full baki')
            ->assertSee('Pay all');

        $this->post(route('field.orders.collect', $order), [
            'amount' => 420,
            'method' => Payment::METHOD_CASH,
        ])->assertSessionHasNoErrors();

        $payment = Payment::query()->where('invoice_id', $order->fresh()->invoice_id)->firstOrFail();
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
        $this->assertEqualsWithDelta(420, (float) $payment->amount, 0.01);
    }

    public function test_salesman_sees_collect_on_deliveries_and_can_pay_when_van_is_at_shop(): void
    {
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $shop = Shop::query()->where('status', Shop::STATUS_ACTIVE)->firstOrFail();
        $shop->update(['assigned_salesman_id' => $salesman->id]);

        ['order' => $order, 'delivery' => $delivery] = $this->dispatchedOrder($shop, $salesman, 510);

        $this->actingAs($salesman)
            ->get(route('field.deliveries'))
            ->assertOk()
            ->assertSee('Baki ৳')
            ->assertSee($delivery->number);

        $this->get(route('field.orders.show', $order))
            ->assertOk()
            ->assertSee('Payment after delivery')
            ->assertSee('Mark delivered')
            ->assertDontSee('name="amount"', false);

        $this->post(route('field.orders.collect', $order), [
            'amount' => 510,
            'method' => Payment::METHOD_CASH,
        ])->assertSessionHasErrors('amount');

        $this->post(route('field.orders.deliver', $order))->assertSessionHasNoErrors();
        $this->assertSame(Order::STATUS_DELIVERED, $order->fresh()->status);

        $this->get(route('field.orders.show', $order))
            ->assertOk()
            ->assertSee('Payment at shop')
            ->assertSee('Full baki');

        $this->post(route('field.orders.collect', $order), [
            'amount' => 510,
            'method' => Payment::METHOD_CASH,
        ])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertNotNull($order->invoice_id);
        $this->assertSame(Payment::STATUS_PENDING, Payment::query()->where('invoice_id', $order->invoice_id)->value('status'));
    }

    public function test_salesman_can_leave_baki_after_delivery_and_collect_in_parts(): void
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $shop = Shop::query()->where('status', Shop::STATUS_ACTIVE)->firstOrFail();

        ['order' => $order, 'delivery' => $delivery] = $this->dispatchedOrder($shop, $salesman, 600);

        $this->actingAs($admin)
            ->post(route('deliveries.deliver', $delivery), ['collected_now' => 0])
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertEqualsWithDelta(600, $order->collectibleBalance(), 0.01);

        $this->actingAs($salesman)
            ->post(route('field.orders.collect', $order), ['amount' => 0])
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(0, Payment::query()->where('invoice_id', $order->invoice_id)->count());
        $this->assertEqualsWithDelta(600, $order->collectibleBalance(), 0.01);

        $this->post(route('field.orders.collect', $order), [
            'amount' => 250,
            'method' => Payment::METHOD_CASH,
        ])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertEqualsWithDelta(350, $order->collectibleBalance(), 0.01);

        $this->get(route('field.orders.show', $order))
            ->assertOk()
            ->assertSee('Payment at shop')
            ->assertSee('Full baki');

        $this->post(route('field.orders.collect', $order), [
            'amount' => 350,
            'method' => Payment::METHOD_CASH,
        ])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(0, $order->fresh(['invoice', 'advanceInvoice'])->collectibleBalance(), 0.01);
        $this->assertSame(2, Payment::query()->where('invoice_id', $order->invoice_id)->count());
    }

    /**
     * @return array{order: Order, delivery: Delivery}
     */
    private function dispatchedOrder(Shop $shop, ?User $salesman = null, float $total = 500): array
    {
        $warehouse = Warehouse::query()->firstOrFail();

        $order = Order::query()->create([
            'number' => 'ORD-PAY-'.substr(uniqid(), -8),
            'shop_id' => $shop->id,
            'warehouse_id' => $warehouse->id,
            'salesman_id' => $salesman?->id,
            'source' => $salesman ? Order::SOURCE_SALESMAN : Order::SOURCE_ADMIN,
            'status' => Order::STATUS_DISPATCHED,
            'subtotal' => $total,
            'discount_total' => 0,
            'total' => $total,
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
            'number' => 'DEL-PAY-'.substr(uniqid(), -8),
            'order_id' => $order->id,
            'fulfilment_id' => $fulfilment->id,
            'warehouse_id' => $warehouse->id,
            'shop_id' => $shop->id,
            'assigned_to' => $salesman?->id,
            'assigned_at' => $salesman ? now() : null,
            'status' => Delivery::STATUS_OUT_FOR_DELIVERY,
            'dispatched_at' => now(),
            'delivery_address' => $shop->address ?: 'Dhaka',
        ]);

        return compact('order', 'delivery');
    }
}
