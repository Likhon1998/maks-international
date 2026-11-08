<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardSystemTotalsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_super_admin_sees_system_sales_expenses_and_profit_on_dashboard(): void
    {
        $super = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $this->assertTrue($super->isSuperAdmin());

        $shop = Shop::query()->where('status', Shop::STATUS_ACTIVE)->firstOrFail();
        $warehouse = Warehouse::query()->firstOrFail();
        $product = Product::query()->where('status', Product::STATUS_ACTIVE)->firstOrFail();
        $product->update(['landed_cost' => 200, 'cost_price' => 180]);

        $order = Order::query()->create([
            'number' => 'ORD-SYS-TOTAL',
            'shop_id' => $shop->id,
            'warehouse_id' => $warehouse->id,
            'source' => Order::SOURCE_ADMIN,
            'status' => Order::STATUS_DELIVERED,
            'subtotal' => 1000,
            'discount_total' => 0,
            'total' => 1000,
            'item_count' => 2,
            'submitted_at' => now(),
            'approved_at' => now(),
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'quantity' => 2,
            'unit_price' => 500,
            'line_total' => 1000,
        ]);

        $totals = app(ReportService::class)->systemTotals();
        $this->assertGreaterThanOrEqual(1000, $totals['sales']);
        $this->assertGreaterThanOrEqual(400, $totals['expenses']);
        $this->assertEqualsWithDelta($totals['sales'] - $totals['expenses'], $totals['profit'], 0.01);

        $this->actingAs($super)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('System totals')
            ->assertSee('Total sales')
            ->assertSee('Total expenses')
            ->assertSee('Total profit');
    }

    public function test_non_super_admin_does_not_see_system_totals(): void
    {
        $adminRole = Role::findOrCreate('Admin', 'web');
        $user = User::factory()->create([
            'portal' => User::PORTAL_ADMIN,
            'is_active' => true,
            'email' => 'plain-admin-dash@test.local',
        ]);
        $user->syncRoles([$adminRole]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('System totals', false);
    }
}
