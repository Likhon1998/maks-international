<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Support\DemoData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SalesReportTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_admin_sales_reports_cover_period_salesman_and_products(): void
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $shop = Shop::query()->where('status', Shop::STATUS_ACTIVE)->firstOrFail();
        $fast = Product::query()->where('status', Product::STATUS_ACTIVE)->firstOrFail();
        $slow = Product::query()->where('status', Product::STATUS_ACTIVE)->where('id', '!=', $fast->id)->firstOrFail();
        $fast->update(['landed_cost' => 100, 'cost_price' => 100, 'name' => 'Report Fast Seller']);
        $slow->update(['landed_cost' => 40, 'cost_price' => 40, 'name' => 'Report Profit King']);

        $day = Carbon::parse('2024-03-15 10:00:00');
        $this->placeSoldOrder('RPT-FAST-1', $shop, $salesman, $fast, 3, 200, $day);
        $this->placeSoldOrder('RPT-SLOW-1', $shop, $salesman, $slow, 1, 400, $day);
        $this->placeSoldOrder('RPT-OUT-1', $shop, $salesman, $fast, 9, 200, $day->copy()->subMonth());
        $this->placeSoldOrder('RPT-CANCEL-1', $shop, $salesman, $fast, 5, 200, $day, Order::STATUS_CANCELLED);

        $period = ['from' => '2024-03-15', 'to' => '2024-03-15'];

        $this->actingAs($admin)
            ->get(route('reports.index', $period))
            ->assertOk()
            ->assertSee('Total sales')
            ->assertSee('Salesman-wise')
            ->assertSee('Best-selling products')
            ->assertSee('Most profitable products')
            ->assertSee('Product-wise sales')
            ->assertSee('Admin sales overview');

        $this->actingAs($admin)
            ->get(route('reports.show', ['report' => 'total-sales'] + $period))
            ->assertOk()
            ->assertSee('Total sales')
            ->assertSee('RPT-FAST-1')
            ->assertSee('RPT-SLOW-1')
            ->assertDontSee('RPT-OUT-1')
            ->assertDontSee('RPT-CANCEL-1')
            ->assertSee($salesman->name)
            ->assertSee(DemoData::taka(1000));

        $this->actingAs($admin)
            ->get(route('reports.show', ['report' => 'salesmen'] + $period))
            ->assertOk()
            ->assertSee($salesman->name)
            ->assertSee(DemoData::taka(1000))
            ->assertSee(DemoData::taka(660));

        $this->actingAs($admin)
            ->get(route('reports.show', ['report' => 'best-sellers'] + $period))
            ->assertOk()
            ->assertSee('Report Fast Seller')
            ->assertSee('Report Profit King');

        $this->actingAs($admin)
            ->get(route('reports.show', ['report' => 'most-profitable'] + $period))
            ->assertOk()
            ->assertSee('Report Profit King');

        $this->actingAs($admin)
            ->get(route('reports.show', ['report' => 'product-sales'] + $period))
            ->assertOk()
            ->assertSee('Report Fast Seller')
            ->assertSee('Report Profit King');

        $this->actingAs($admin)
            ->get(route('reports.show', ['report' => 'overview'] + $period))
            ->assertOk()
            ->assertSee('Salesman-wise sales & profit')
            ->assertSee('Product-wise sales')
            ->assertSee('Best-selling products')
            ->assertSee('Most profitable products')
            ->assertSee($salesman->name)
            ->assertSee('Report Fast Seller');

        $csv = $this->actingAs($admin)
            ->get(route('reports.export', ['report' => 'salesmen'] + $period));
        $csv->assertOk();
        $this->assertStringContainsString($salesman->name, $csv->streamedContent());
    }

    private function placeSoldOrder(
        string $number,
        Shop $shop,
        User $salesman,
        Product $product,
        int $qty,
        float $unit,
        Carbon $when,
        string $status = Order::STATUS_DELIVERED,
    ): Order {
        $order = Order::query()->create([
            'number' => $number,
            'shop_id' => $shop->id,
            'salesman_id' => $salesman->id,
            'source' => Order::SOURCE_SALESMAN,
            'status' => $status,
            'subtotal' => $qty * $unit,
            'total' => $qty * $unit,
            'item_count' => $qty,
            'submitted_at' => $when,
            'created_by' => $salesman->id,
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'quantity' => $qty,
            'original_unit_price' => $unit,
            'unit_price' => $unit,
            'line_total' => $qty * $unit,
        ]);

        return $order;
    }
}
