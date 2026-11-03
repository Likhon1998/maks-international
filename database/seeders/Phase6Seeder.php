<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\Shipment;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseService;
use App\Services\ShipmentService;
use Illuminate\Database\Seeder;

class Phase6Seeder extends Seeder
{
    public function run(): void
    {
        $purchases = app(PurchaseService::class);
        $shipments = app(ShipmentService::class);
        $admin = User::query()->where('email', 'admin@maksinternational.com')->first();
        $warehouse = Warehouse::defaultWarehouse();

        $supplier = Supplier::query()->updateOrCreate(
            ['code' => 'SUP-0001'],
            [
                'name' => 'Shenzhen Gadget Source Co.',
                'country' => 'China',
                'city' => 'Shenzhen',
                'contact_name' => 'Li Wei',
                'phone' => '+86 755 1234 5678',
                'email' => 'export@szgadgets.cn',
                'payment_terms' => '30% TT advance',
                'is_active' => true,
                'notes' => 'Primary China OEM for audio & power accessories',
            ],
        );

        Supplier::query()->updateOrCreate(
            ['code' => 'SUP-0002'],
            [
                'name' => 'Guangzhou Wearables Ltd',
                'country' => 'China',
                'city' => 'Guangzhou',
                'contact_name' => 'Chen Mei',
                'phone' => '+86 20 8765 4321',
                'is_active' => true,
            ],
        );

        if (! Purchase::query()->where('number', 'like', 'PO-%')->exists()) {
            $products = Product::query()->whereIn('sku', ['BT-EB-01', 'PW-BK-20', 'CG-CH-33'])->get();
            if ($products->isEmpty()) {
                $products = Product::query()->take(3)->get();
            }

            $lines = $products->values()->map(fn ($p, $i) => [
                'product_id' => $p->id,
                'quantity' => [500, 300, 800][$i] ?? 200,
                'unit_cost_foreign' => [38, 55, 9.5][$i] ?? 20,
            ])->all();

            $po = $purchases->create([
                'supplier_id' => $supplier->id,
                'currency' => 'CNY',
                'exchange_rate' => 16.5,
                'ordered_at' => now()->subDays(18)->toDateString(),
                'expected_at' => now()->addDays(5)->toDateString(),
                'notes' => 'Seeded Phase 6 China PO',
                'status' => Purchase::STATUS_ORDERED,
            ], $lines, $admin);

            $shipment = $shipments->createFromPurchase($po, [
                'origin' => 'Shenzhen, China',
                'carrier' => 'COSCO Shipping',
                'tracking_ref' => 'COSCO-BD-77821',
                'container_no' => 'CSNU1234567',
                'warehouse_id' => $warehouse?->id,
                'shipped_at' => now()->subDays(12)->toDateString(),
                'eta_at' => now()->addDays(2)->toDateString(),
                'freight_cost' => 125000,
                'customs_duty' => 68000,
                'insurance_cost' => 12000,
                'other_cost' => 7500,
                'status' => Shipment::STATUS_IN_TRANSIT,
                'notes' => 'Seeded inbound container',
            ], $admin);

            // Second shipment already received to demonstrate landed cost update.
            $products2 = Product::query()->where('sku', 'SM-WT-09')->get();
            if ($products2->isEmpty()) {
                $products2 = Product::query()->skip(3)->take(1)->get();
            }

            if ($products2->isNotEmpty()) {
                $received = $shipments->create([
                    'supplier_id' => $supplier->id,
                    'warehouse_id' => $warehouse?->id,
                    'origin' => 'Guangzhou, China',
                    'carrier' => 'Maersk',
                    'tracking_ref' => 'MAEU-BD-44102',
                    'container_no' => 'MAEU9988776',
                    'shipped_at' => now()->subDays(30)->toDateString(),
                    'eta_at' => now()->subDays(8)->toDateString(),
                    'freight_cost' => 45000,
                    'customs_duty' => 22000,
                    'insurance_cost' => 4000,
                    'other_cost' => 2500,
                    'status' => Shipment::STATUS_ARRIVED,
                    'notes' => 'Seeded received shipment',
                ], [[
                    'product_id' => $products2->first()->id,
                    'quantity' => 120,
                    'unit_cost_bdt' => 2650,
                ]], $admin);

                if ($admin) {
                    $shipments->receive($received->fresh(), $admin, $warehouse?->id);
                }
            }
        }
    }
}
