<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CommissionRule;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderFulfilment;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopVisit;
use App\Models\User;
use App\Services\CollectionSummaryService;
use App\Services\CommissionService;
use App\Services\CreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoneyIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_order_line_totals_discount_and_settlement_math_are_exact(): void
    {
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $shop = Shop::query()->where('status', Shop::STATUS_ACTIVE)->firstOrFail();
        $shop->update(['assigned_salesman_id' => $salesman->id, 'outstanding_balance' => 0]);
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

        $unit = round((float) $product->priceForGroup($shop->priceGroup), 2);
        $qty = 3;
        $discount = round(min(20.20, max(0, $unit - 1)), 2);
        $expectedSub = round($unit * $qty, 2);
        $expectedTotal = round($expectedSub - $discount, 2);

        $this->actingAs($salesman)
            ->post(route('field.visit.order', $visit), [
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => $qty,
                    'unit_price' => $unit,
                ]],
                'discount_total' => $discount,
                'paid_now' => 0,
                'notes' => 'Exact money integrity order.',
            ])
            ->assertSessionHasNoErrors();

        $order = Order::query()->where('visit_id', $visit->id)->firstOrFail();
        $this->assertEqualsWithDelta($expectedSub, (float) $order->subtotal, 0.001);
        $this->assertEqualsWithDelta($discount, (float) $order->discount_total, 0.001);
        $this->assertEqualsWithDelta($expectedTotal, (float) $order->total, 0.001);
        $this->assertEqualsWithDelta($expectedSub, (float) $order->items->sum('line_total'), 0.001);

        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $this->actingAs($admin)->post(route('orders.invoice', $order))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('orders.approve', $order), ['credit_override' => 1])->assertSessionHasNoErrors();
        $this->dispatchAssignedTo($order->fresh(), $salesman, $admin);
        $this->actingAs($salesman)->post(route('field.orders.deliver', $order))->assertSessionHasNoErrors();

        $order->refresh()->load(['invoice', 'advanceInvoice']);
        $settle = $order->settlement();

        $this->assertEqualsWithDelta($expectedTotal, $settle['order_total'], 0.001);
        $this->assertEqualsWithDelta($expectedTotal, $settle['remaining_due'], 0.001);
        $this->assertEqualsWithDelta($expectedTotal, $settle['baki'], 0.001);
        $this->assertEqualsWithDelta($expectedTotal, $order->collectibleBalance(), 0.001);
        $this->assertEqualsWithDelta(
            $expectedTotal,
            (float) $shop->fresh()->outstanding_balance,
            0.001
        );

        $half = round($expectedTotal / 2, 2);
        $this->actingAs($salesman)
            ->post(route('field.orders.collect', $order), [
                'amount' => $half,
                'method' => Payment::METHOD_CASH,
            ])
            ->assertSessionHasNoErrors();

        $order->refresh()->load(['invoice', 'advanceInvoice']);
        $after = $order->settlement();
        $this->assertEqualsWithDelta($half, $after['pending_sales'], 0.001);
        $this->assertEqualsWithDelta($expectedTotal, $after['remaining_due'], 0.001);
        $this->assertEqualsWithDelta(round($expectedTotal - $half, 2), $after['baki'], 0.001);
        $this->assertEqualsWithDelta(round($expectedTotal - $half, 2), $order->collectibleBalance(), 0.001);

        $payment = Payment::query()
            ->where('invoice_id', $order->invoice_id)
            ->where('status', Payment::STATUS_PENDING)
            ->firstOrFail();

        $this->actingAs($admin)
            ->post(route('payments.verify', $payment))
            ->assertSessionHasNoErrors();

        $order->refresh()->load(['invoice', 'advanceInvoice']);
        $verified = $order->settlement();
        $this->assertEqualsWithDelta($half, $verified['sales_paid'], 0.001);
        $this->assertEqualsWithDelta(0, $verified['pending_sales'], 0.001);
        $this->assertEqualsWithDelta(round($expectedTotal - $half, 2), $verified['remaining_due'], 0.001);
        $this->assertEqualsWithDelta(round($expectedTotal - $half, 2), $verified['baki'], 0.001);
        $this->assertEqualsWithDelta(
            round($expectedTotal - $half, 2),
            (float) $shop->fresh()->outstanding_balance,
            0.001
        );

        $commission = Commission::query()
            ->where('payment_id', $payment->id)
            ->where('type', Commission::TYPE_COLLECTION)
            ->firstOrFail();
        $rule = CommissionRule::activeDefault();
        $rate = (float) ($rule?->collection_rate_percent ?? 3.5);
        $expectedCommission = round($half * $rate / 100, 2);

        $this->assertEqualsWithDelta($half, (float) $commission->base_amount, 0.001);
        $this->assertEqualsWithDelta($rate, (float) $commission->rate_percent, 0.001);
        $this->assertEqualsWithDelta($expectedCommission, (float) $commission->commission_amount, 0.001);
        $this->assertEqualsWithDelta(
            round((float) $commission->base_amount * (float) $commission->rate_percent / 100, 2),
            (float) $commission->commission_amount,
            0.001
        );
    }

    public function test_open_dues_order_sum_matches_collectable_and_explains_shop_baki(): void
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
                    'quantity' => 2,
                    'unit_price' => $unit,
                ]],
                'paid_now' => 0,
                'notes' => 'Dues sum integrity.',
            ])
            ->assertSessionHasNoErrors();

        $order = Order::query()->where('visit_id', $visit->id)->firstOrFail();
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $this->actingAs($admin)->post(route('orders.invoice', $order))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('orders.approve', $order), ['credit_override' => 1])->assertSessionHasNoErrors();
        $this->dispatchAssignedTo($order->fresh(), $salesman, $admin);
        $this->actingAs($salesman)->post(route('field.orders.deliver', $order))->assertSessionHasNoErrors();

        app(CreditService::class)->recalculateOutstanding($shop->fresh());
        $shop->refresh();

        $row = app(CollectionSummaryService::class)->openDuesForShop($salesman, $shop);
        $this->assertNotNull($row);
        $this->assertGreaterThan(0.009, $row['baki']);
        $this->assertEqualsWithDelta($row['orders']->sum('due'), $row['orders_due'], 0.001);
        $this->assertEqualsWithDelta($row['orders']->where('can_collect', true)->sum('due'), $row['collectable'], 0.001);
        $pending = (float) \App\Models\Payment::query()
            ->where('shop_id', $shop->id)
            ->where('status', \App\Models\Payment::STATUS_PENDING)
            ->sum('amount');
        $this->assertEqualsWithDelta(
            max(max(0, (float) $shop->outstanding_balance - $pending), (float) $row['orders_due']),
            $row['baki'],
            0.001
        );

        $orderDue = $row['orders']->firstWhere(fn ($r) => (int) $r['order']->id === (int) $order->id);
        $this->assertNotNull($orderDue);
        $this->assertEqualsWithDelta($order->fresh(['invoice', 'advanceInvoice'])->collectibleBalance(), $orderDue['due'], 0.001);
        $this->assertEqualsWithDelta($orderDue['due'] + $orderDue['pending'], $orderDue['remaining'], 0.001);
    }

    public function test_commission_base_matches_amount_used_for_rate(): void
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $shop = Shop::query()->where('status', Shop::STATUS_ACTIVE)->firstOrFail();

        $invoice = Invoice::query()->create([
            'number' => 'INV-MATH-'.uniqid(),
            'shop_id' => $shop->id,
            'status' => Invoice::STATUS_ISSUED,
            'subtotal' => 1000,
            'discount_total' => 0,
            'total' => 1000,
            'paid_amount' => 0,
            'balance' => 1000,
            'issued_at' => now(),
            'due_at' => now()->addDays(7)->toDateString(),
            'created_by' => $admin->id,
        ]);

        $payment = Payment::query()->create([
            'number' => 'PAY-MATH-'.uniqid(),
            'shop_id' => $shop->id,
            'invoice_id' => $invoice->id,
            'amount' => 250,
            'method' => Payment::METHOD_CASH,
            'status' => Payment::STATUS_VERIFIED,
            'paid_at' => now(),
            'verified_at' => now(),
            'created_by' => $salesman->id,
            'verified_by' => $admin->id,
        ]);

        // Tie payment to a salesman-owned order so commission resolves.
        $order = Order::query()->create([
            'number' => 'ORD-MATH-'.uniqid(),
            'shop_id' => $shop->id,
            'salesman_id' => $salesman->id,
            'source' => Order::SOURCE_SALESMAN,
            'status' => Order::STATUS_DELIVERED,
            'subtotal' => 1000,
            'discount_total' => 0,
            'total' => 1000,
            'item_count' => 1,
            'invoice_id' => $invoice->id,
            'submitted_at' => now(),
            'created_by' => $salesman->id,
        ]);
        $invoice->update(['order_id' => $order->id]);

        $commission = app(CommissionService::class)->accrueFromPayment($payment->fresh(['invoice.order', 'invoice.shop', 'shop']), $admin);
        $this->assertNotNull($commission);

        $expected = round((float) $commission->base_amount * (float) $commission->rate_percent / 100, 2);
        $this->assertEqualsWithDelta($expected, (float) $commission->commission_amount, 0.001);
        $this->assertEqualsWithDelta(250, (float) $commission->base_amount, 0.001);
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
            'tracking_ref' => 'MATH-'.$order->id,
        ])->assertSessionHasNoErrors();
    }
}
