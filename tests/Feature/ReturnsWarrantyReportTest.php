<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\ReturnItem;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReturnsWarrantyReportTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_returns_warranty_report_lists_handling_fields_and_exports(): void
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $shop = Shop::query()->where('status', Shop::STATUS_ACTIVE)->firstOrFail();
        $product = Product::query()->where('status', Product::STATUS_ACTIVE)->firstOrFail();
        $product->update(['name' => 'Warranty Probe Charger', 'warranty' => '12 months']);
        $day = Carbon::parse('2024-03-15 09:00:00');

        $return = ProductReturn::query()->create([
            'number' => 'RET-RPT-1',
            'shop_id' => $shop->id,
            'status' => ProductReturn::STATUS_APPROVED,
            'reason_type' => ProductReturn::REASON_WARRANTY,
            'total' => 500,
            'credit_issued' => true,
            'refund_amount' => 500,
            'replaced' => true,
            'repair_status' => ProductReturn::REPAIR_NONE,
            'inventory_condition' => ProductReturn::CONDITION_DAMAGED,
            'created_by' => $admin->id,
        ]);
        $return->forceFill(['created_at' => $day, 'updated_at' => $day])->save();
        ReturnItem::query()->create([
            'return_id' => $return->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'quantity' => 2,
            'unit_price' => 250,
            'line_total' => 500,
        ]);

        $period = ['from' => '2024-03-01', 'to' => '2024-03-31'];

        $this->actingAs($admin)
            ->get(route('reports.show', ['report' => 'returns'] + $period))
            ->assertOk()
            ->assertSee('Warranty Probe Charger')
            ->assertSee('Return reason')
            ->assertSee('Warranty period')
            ->assertSee('Warranty expiry')
            ->assertSee('Replacement')
            ->assertSee('Repair status')
            ->assertSee('Refund amount')
            ->assertSee('Inventory update')
            ->assertSee('Product-wise return rate')
            ->assertSee('Warranty only')
            ->assertSee($shop->name);

        $this->actingAs($admin)
            ->get(route('reports.show', ['report' => 'returns', 'kind' => 'warranty'] + $period))
            ->assertOk()
            ->assertSee('Warranty Probe Charger');

        $this->actingAs($admin)
            ->get(route('reports.export', ['report' => 'returns', 'format' => 'xlsx'] + $period))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('reports.export', ['report' => 'returns', 'format' => 'pdf'] + $period))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('returns.index', ['kind' => 'warranty', 'q' => 'Warranty Probe']))
            ->assertOk()
            ->assertSee('Warranty Probe Charger')
            ->assertSee($shop->name)
            ->assertSee('Warranty');

        $this->actingAs($admin)
            ->get(route('returns.show', $return))
            ->assertOk()
            ->assertSee('Warranty period')
            ->assertSee('Replacement')
            ->assertSee('Inventory update');
    }
}
