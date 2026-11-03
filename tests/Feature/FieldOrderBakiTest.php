<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopVisit;
use App\Models\User;
use App\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldOrderBakiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_visit_shows_category_first_browse_and_price_edit(): void
    {
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $shop = Shop::query()->where('status', Shop::STATUS_ACTIVE)->firstOrFail();
        $shop->update(['assigned_salesman_id' => $salesman->id]);

        ShopVisit::query()
            ->where('salesman_id', $salesman->id)
            ->whereNull('checked_out_at')
            ->update(['checked_out_at' => now()]);

        $visit = ShopVisit::query()->create([
            'shop_id' => $shop->id,
            'salesman_id' => $salesman->id,
            'checked_in_at' => now(),
            'purpose' => 'Order collection',
            'outcome' => ShopVisit::OUTCOME_IN_PROGRESS,
        ]);

        $product = Product::query()
            ->where('status', Product::STATUS_ACTIVE)
            ->where('is_published', true)
            ->with('category')
            ->firstOrFail();

        $shop->update(['outstanding_balance' => 2500]);

        $this->actingAs($salesman)
            ->get(route('field.visit.show', $visit))
            ->assertOk()
            ->assertSee('All products', false)
            ->assertSee('fieldOrder(', false)
            ->assertSee($product->name)
            ->assertSee('f-price-edit', false)
            ->assertDontSee('paid_now', false)
            ->assertSee('No payment on this order now', false)
            ->assertSee('What did you talk about with the owner?', false)
            ->assertSee('This order', false)
            ->assertSee('Previous baki', false)
            ->assertSee('Discount', false)
            ->assertSee('+50', false)
            ->assertSee('+100', false)
            ->assertSee('Unavailable', false)
            ->assertSee(':disabled="p.stock <= 0"', false);
    }

    public function test_out_of_stock_product_cannot_be_ordered(): void
    {
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $shop = Shop::query()->where('status', Shop::STATUS_ACTIVE)->firstOrFail();
        $shop->update(['assigned_salesman_id' => $salesman->id]);
        $product = Product::query()
            ->where('status', Product::STATUS_ACTIVE)
            ->where('is_published', true)
            ->firstOrFail();

        $product->forceFill(['stock_on_hand' => 0, 'reserved_stock' => 0])->save();
        WarehouseStock::query()->where('product_id', $product->id)->update([
            'qty_on_hand' => 0,
            'qty_reserved' => 0,
        ]);

        ShopVisit::query()
            ->where('salesman_id', $salesman->id)
            ->whereNull('checked_out_at')
            ->update(['checked_out_at' => now()]);

        $visit = ShopVisit::query()->create([
            'shop_id' => $shop->id,
            'salesman_id' => $salesman->id,
            'checked_in_at' => now(),
        ]);

        $this->actingAs($salesman)
            ->get(route('field.visit.show', $visit))
            ->assertOk()
            ->assertSee('\u0022stock\u0022:0', false)
            ->assertSee($product->name);

        $this->actingAs($salesman)
            ->post(route('field.visit.order', $visit), [
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => (float) $product->priceForGroup($shop->priceGroup),
                ]],
                'notes' => 'Tried to take a sold-out item.',
            ])
            ->assertSessionHasErrors('stock');

        $this->assertTrue($visit->fresh()->isOpen());
        $this->assertNull(Order::query()->where('visit_id', $visit->id)->first());
    }

    public function test_send_order_requires_talk_notes(): void
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

        $this->actingAs($salesman)
            ->post(route('field.visit.order', $visit), [
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => (float) $product->priceForGroup($shop->priceGroup),
                ]],
            ])
            ->assertSessionHasErrors('notes');

        $this->assertTrue($visit->fresh()->isOpen());
    }

    public function test_salesman_can_reduce_order_total_with_discount(): void
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
        $subtotal = round($unit * 2, 2);
        $discount = min(50, round($subtotal / 10, 2));

        $this->actingAs($salesman)
            ->post(route('field.visit.order', $visit), [
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_price' => $unit,
                ]],
                'discount_total' => $discount,
                'paid_now' => 0,
                'notes' => 'Talked about a small discount on this order.',
            ])
            ->assertSessionHasNoErrors();

        $order = Order::query()->where('visit_id', $visit->id)->firstOrFail();
        $this->assertEqualsWithDelta($subtotal, (float) $order->subtotal, 0.05);
        $this->assertEqualsWithDelta($discount, (float) $order->discount_total, 0.05);
        $this->assertEqualsWithDelta($subtotal - $discount, (float) $order->total, 0.05);

        $this->actingAs($salesman)
            ->get(route('field.orders.show', $order))
            ->assertOk()
            ->assertSee('Reduce total', false);

        $this->actingAs($salesman)
            ->post(route('field.orders.discount', $order), ['discount_total' => $discount + 1])
            ->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta($discount + 1, (float) $order->fresh()->discount_total, 0.05);

        $this->actingAs($salesman)
            ->post(route('field.orders.discount', $order), ['add' => 1, 'discount_total' => 1])
            ->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta($discount + 2, (float) $order->fresh()->discount_total, 0.05);

        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $this->actingAs($admin)
            ->post(route('orders.discount', $order), ['discount_total' => $discount + 2])
            ->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta($discount + 2, (float) $order->fresh()->discount_total, 0.05);
    }

    public function test_salesman_can_collect_partial_payment_and_leave_baki(): void
    {
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $shop = Shop::query()->where('status', Shop::STATUS_ACTIVE)->firstOrFail();
        $shop->update(['assigned_salesman_id' => $salesman->id, 'credit_limit' => 500000]);
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
        $qty = 2;
        $total = round($unit * $qty, 2);
        $paidNow = round($total / 2, 2);
        $baki = round($total - $paidNow, 2);

        $this->actingAs($salesman)
            ->post(route('field.visit.order', $visit), [
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => $qty,
                    'unit_price' => $unit,
                ]],
                'paid_now' => $paidNow,
                'payment_method' => Payment::METHOD_CASH,
                'notes' => 'Owner will pay on delivery',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $order = Order::query()->where('visit_id', $visit->id)->firstOrFail();

        $this->assertSame(Order::STATUS_PENDING_AUDIT, $order->status);
        $this->assertFalse((bool) $order->advance_required);
        $this->assertSame(0, Payment::query()->where('shop_id', $shop->id)->where('created_by', $salesman->id)->count());

        $this->actingAs($salesman)
            ->get(route('field.orders.show', $order))
            ->assertOk()
            ->assertSee('Payment after delivery', false)
            ->assertDontSee('name="amount"', false);

        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $this->actingAs($admin)
            ->post(route('orders.approve', $order), ['credit_override' => 1])
            ->assertSessionHasNoErrors();

        $this->actingAs($salesman)
            ->post(route('field.orders.collect', $order), [
                'amount' => $paidNow,
                'method' => Payment::METHOD_CASH,
            ])
            ->assertSessionHasErrors('amount');

        $this->actingAs($salesman)
            ->post(route('field.orders.deliver', $order))
            ->assertSessionHasNoErrors();

        $this->actingAs($salesman)
            ->post(route('field.orders.collect', $order), [
                'amount' => $paidNow,
                'method' => Payment::METHOD_CASH,
            ])
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertEqualsWithDelta($baki, $order->collectibleBalance(), 0.05);
        $this->assertTrue(
            Payment::query()
                ->where('shop_id', $shop->id)
                ->where('invoice_id', $order->invoice_id)
                ->where('status', Payment::STATUS_PENDING)
                ->where('amount', $paidNow)
                ->exists()
        );
    }

    public function test_full_baki_order_stays_pending_audit_without_advance(): void
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

        $this->actingAs($salesman)
            ->post(route('field.visit.order', $visit), [
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => (float) $product->priceForGroup($shop->priceGroup),
                ]],
                'paid_now' => 0,
                'notes' => 'Owner will take the full amount as baki.',
            ])
            ->assertSessionHasNoErrors();

        $order = Order::query()->where('visit_id', $visit->id)->firstOrFail();
        $this->assertSame(Order::STATUS_PENDING_AUDIT, $order->status);
        $this->assertFalse((bool) $order->advance_required);
        $this->assertTrue($order->settlement()['collectible']);

        $this->actingAs($salesman)
            ->get(route('field.orders.show', $order))
            ->assertOk()
            ->assertSee('Payment after delivery', false)
            ->assertDontSee('name="amount"', false);

        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $owedBefore = (float) $shop->fresh()->outstanding_balance;
        $this->actingAs($admin)
            ->post(route('orders.invoice', $order))
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertNotNull($order->invoice_id);
        $this->assertTrue($order->settlement()['invoice_issued']);
        $this->assertTrue($order->settlement()['collectible']);
        $this->assertEqualsWithDelta(
            $owedBefore + (float) $order->total,
            (float) $shop->fresh()->outstanding_balance,
            0.05
        );
    }

    public function test_pending_collection_stays_on_this_order_not_previous_baki(): void
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
                'notes' => 'Talked about paying this bill after delivery.',
            ])
            ->assertSessionHasNoErrors();

        $order = Order::query()->where('visit_id', $visit->id)->firstOrFail();
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $this->actingAs($admin)->post(route('orders.invoice', $order))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('orders.approve', $order), ['credit_override' => 1])->assertSessionHasNoErrors();

        $this->actingAs($salesman)
            ->post(route('field.orders.deliver', $order))
            ->assertSessionHasNoErrors();

        $order->refresh()->load(['shop', 'invoice']);
        $prevBefore = $order->previousShopBaki();
        $due = (float) $order->collectibleBalance();
        $this->assertGreaterThan(0.009, $due);

        $this->actingAs($salesman)
            ->post(route('field.orders.collect', $order), [
                'amount' => $due,
                'method' => Payment::METHOD_CASH,
            ])
            ->assertSessionHasNoErrors();

        $order->refresh()->load(['shop', 'invoice']);
        $settle = $order->settlement();

        $this->assertEqualsWithDelta($due, $settle['remaining_due'], 0.05);
        $this->assertEqualsWithDelta($due, $settle['pending_sales'], 0.05);
        $this->assertEqualsWithDelta(0, $order->collectibleBalance(), 0.05);
        $this->assertEqualsWithDelta($prevBefore, $order->previousShopBaki(), 0.05);

        $page = $this->actingAs($salesman)->get(route('field.orders.show', $order))->assertOk();
        $page->assertSee('This order baki', false);
        $page->assertSee('Waiting confirm', false);
        $page->assertSee('waiting for office to confirm', false);
    }
}
