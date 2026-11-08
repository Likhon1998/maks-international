<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\CreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DueManagementTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_dues_page_shows_live_outstanding_after_delivery(): void
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $shop = Shop::query()->where('status', Shop::STATUS_ACTIVE)->firstOrFail();
        $shop->update(['outstanding_balance' => 0]);

        $order = $this->deliverOrderFor($shop, 2);
        $invoice = Invoice::findOrFail($order->invoice_id);

        app(CreditService::class)->recalculateOutstanding($shop->fresh());

        $this->actingAs($admin)
            ->get(route('dues.index'))
            ->assertOk()
            ->assertSee($shop->name)
            ->assertSee('Open invoices')
            ->assertSee('Collect');

        $this->assertGreaterThan(0, (float) $shop->fresh()->outstanding_balance);
        $this->assertEqualsWithDelta((float) $invoice->fresh()->balance, (float) $shop->fresh()->outstanding_balance, 0.05);
    }

    public function test_shop_level_payment_against_due_reduces_invoice_and_outstanding(): void
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $shop = Shop::query()->where('status', Shop::STATUS_ACTIVE)->firstOrFail();
        $order = $this->deliverOrderFor($shop, 3);
        $invoice = Invoice::findOrFail($order->invoice_id);
        $owed = (float) app(CreditService::class)->recalculateOutstanding($shop->fresh())->outstanding_balance;
        $this->assertGreaterThan(0, $owed);

        $pay = round($owed / 2, 2);

        $this->actingAs($admin)
            ->post(route('payments.store'), [
                'shop_id' => $shop->id,
                'invoice_id' => null,
                'amount' => $pay,
                'method' => 'cash',
                'verify_now' => '1',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $invoice->refresh();
        $shop->refresh();

        $this->assertEqualsWithDelta((float) $invoice->total - $pay, (float) $invoice->balance, 0.05);
        $this->assertEqualsWithDelta($owed - $pay, (float) $shop->outstanding_balance, 0.05);
        $this->assertTrue(
            Payment::query()->where('shop_id', $shop->id)->where('invoice_id', $invoice->id)->where('status', Payment::STATUS_VERIFIED)->exists()
        );

        $this->actingAs($admin)
            ->get(route('dues.index'))
            ->assertOk()
            ->assertSee($shop->name);
    }

    public function test_collecting_full_due_clears_shop_from_dues_list(): void
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $shop = Shop::query()->where('status', Shop::STATUS_ACTIVE)->firstOrFail();
        $order = $this->deliverOrderFor($shop, 1);
        $owed = (float) app(CreditService::class)->recalculateOutstanding($shop->fresh())->outstanding_balance;

        $this->actingAs($admin)
            ->post(route('payments.store'), [
                'shop_id' => $shop->id,
                'amount' => $owed,
                'method' => 'cash',
                'verify_now' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(0, (float) $shop->fresh()->outstanding_balance, 0.05);
        $this->assertSame(Invoice::STATUS_PAID, Invoice::findOrFail($order->invoice_id)->status);

        $this->actingAs($admin)
            ->get(route('dues.index'))
            ->assertOk()
            ->assertDontSee($shop->code);
    }

    private function deliverOrderFor(Shop $shop, int $qty): Order
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $product = Product::query()->where('status', Product::STATUS_ACTIVE)->where('is_published', true)->firstOrFail();

        WarehouseStock::query()->where('product_id', $product->id)->update([
            'qty_on_hand' => max(500, $qty * 20),
            'qty_reserved' => 0,
        ]);
        $product->forceFill(['stock_on_hand' => max(500, $qty * 20), 'reserved_stock' => 0])->save();

        $this->actingAs($admin)->post(route('orders.store'), [
            'shop_id' => $shop->id,
            'salesman_id' => $salesman->id,
            'items' => [['product_id' => $product->id, 'quantity' => $qty]],
        ])->assertSessionHasNoErrors();

        $order = Order::query()->where('shop_id', $shop->id)->latest('id')->firstOrFail();
        $this->post(route('orders.approve', $order))->assertSessionHasNoErrors();
        $this->post(route('orders.deliver', $order), [
            'assigned_to' => $salesman->id,
        ])->assertSessionHasNoErrors();

        return $order->fresh();
    }
}
