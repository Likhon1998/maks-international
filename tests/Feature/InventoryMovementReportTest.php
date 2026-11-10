<?php

namespace Tests\Feature;

use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InventoryMovementReportTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_inventory_movement_report_shows_buckets_history_and_exports(): void
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $warehouse = Warehouse::defaultWarehouse() ?? Warehouse::query()->firstOrFail();
        $product = Product::query()->where('status', Product::STATUS_ACTIVE)->firstOrFail();
        $product->update(['name' => 'Movement Probe Phone']);

        $before = Carbon::parse('2024-02-01 10:00:00');
        $day = Carbon::parse('2024-03-15 11:00:00');

        $this->ledger($warehouse->id, $product->id, $admin->id, InventoryLedger::TYPE_RECEIVE, 5, 'Opening receipt', $before);
        $this->ledger($warehouse->id, $product->id, $admin->id, InventoryLedger::TYPE_RECEIVE, 10, 'New shipment', $day);
        $this->ledger($warehouse->id, $product->id, $admin->id, InventoryLedger::TYPE_PICK, -4, 'Picked for fulfilment', $day);
        $this->ledger($warehouse->id, $product->id, $admin->id, InventoryLedger::TYPE_RECEIVE, 2, 'Return RET-PROBE restock', $day, 'RET-PROBE');
        $this->ledger($warehouse->id, $product->id, $admin->id, InventoryLedger::TYPE_ADJUST, -1, 'Damaged in warehouse', $day);
        $this->ledger($warehouse->id, $product->id, $admin->id, InventoryLedger::TYPE_ADJUST, -1, 'Supplier return RTV', $day);

        $period = ['from' => '2024-03-01', 'to' => '2024-03-31'];

        $this->actingAs($admin)
            ->get(route('reports.show', ['report' => 'inventory'] + $period))
            ->assertOk()
            ->assertSee('Opening stock')
            ->assertSee('Stock in')
            ->assertSee('Stock out')
            ->assertSee('Product return')
            ->assertSee('Supplier return')
            ->assertSee('Damage / lost')
            ->assertSee('Stock adjustment')
            ->assertSee('Closing stock')
            ->assertSee('Movement Probe Phone')
            ->assertSee('Full movement history')
            ->assertSee($admin->name)
            ->assertSee('This month');

        $this->actingAs($admin)
            ->get(route('reports.show', ['report' => 'inventory', 'q' => 'Movement Probe', 'flow' => 'in'] + $period))
            ->assertOk()
            ->assertSee('Movement Probe Phone')
            ->assertSee('Stock in — movement details')
            ->assertDontSee('Full movement history');

        $csv = $this->actingAs($admin)
            ->get(route('reports.export', ['report' => 'inventory', 'format' => 'csv'] + $period));
        $csv->assertOk();
        $this->assertStringContainsString('Opening stock', $csv->streamedContent());
        $this->assertStringContainsString('Movement Probe Phone', $csv->streamedContent());

        $this->actingAs($admin)
            ->get(route('reports.export', ['report' => 'inventory', 'format' => 'xlsx'] + $period))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.ms-excel; charset=UTF-8');

        $this->actingAs($admin)
            ->get(route('reports.export', ['report' => 'inventory', 'format' => 'pdf'] + $period))
            ->assertOk();
    }

    private function ledger(
        int $warehouseId,
        int $productId,
        int $userId,
        string $type,
        int $qty,
        string $notes,
        Carbon $when,
        ?string $reference = null,
    ): void {
        $entry = InventoryLedger::query()->create([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'type' => $type,
            'qty_delta' => $qty,
            'qty_on_hand_after' => 0,
            'qty_reserved_after' => 0,
            'notes' => $notes,
            'reference' => $reference,
            'created_by' => $userId,
        ]);
        $entry->forceFill(['created_at' => $when, 'updated_at' => $when])->save();
    }
}
