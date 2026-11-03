<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderFulfilment;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\FulfilmentService;
use App\Services\InventoryService;
use App\Services\OrderService;
use Illuminate\Database\Seeder;

class Phase5Seeder extends Seeder
{
    public function run(): void
    {
        $inventory = app(InventoryService::class);
        $fulfilment = app(FulfilmentService::class);
        $orders = app(OrderService::class);
        $admin = User::query()->where('email', 'admin@maksinternational.com')->first();

        $dhaka = Warehouse::query()->updateOrCreate(
            ['code' => 'WH-DHK'],
            [
                'name' => 'Dhaka Central Warehouse',
                'city' => 'Dhaka',
                'address' => 'Tejgaon Industrial Area, Dhaka',
                'phone' => '01710000001',
                'is_default' => true,
                'is_active' => true,
                'manager_id' => $admin?->id,
            ],
        );

        Warehouse::query()->where('id', '!=', $dhaka->id)->update(['is_default' => false]);

        Warehouse::query()->updateOrCreate(
            ['code' => 'WH-CTG'],
            [
                'name' => 'Chattogram Hub',
                'city' => 'Chattogram',
                'address' => 'Agrabad Commercial Area',
                'phone' => '01810000002',
                'is_default' => false,
                'is_active' => true,
            ],
        );

        foreach (Product::query()->get() as $product) {
            WarehouseStock::query()->updateOrCreate(
                ['warehouse_id' => $dhaka->id, 'product_id' => $product->id],
                [
                    'qty_on_hand' => max((int) $product->stock_on_hand, (int) $product->reserved_stock),
                    'qty_reserved' => (int) $product->reserved_stock,
                ],
            );
            $inventory->syncProductTotals($product->fresh());
        }

        // Approve pending audit orders now that a warehouse exists.
        if ($admin) {
            Order::query()
                ->where('status', Order::STATUS_PENDING_AUDIT)
                ->orderBy('id')
                ->get()
                ->each(function (Order $order) use ($orders, $admin) {
                    try {
                        $orders->approve($order, $admin, 'Seeded Phase 5 approval after warehouse setup', false);
                    } catch (\Throwable) {
                        // leave pending
                    }
                });
        }

        Order::query()
            ->where('status', Order::STATUS_APPROVED)
            ->whereDoesntHave('fulfilment')
            ->get()
            ->each(function (Order $order) use ($fulfilment, $dhaka, $admin) {
                $order->update(['warehouse_id' => $dhaka->id]);
                $fulfilment->createForApprovedOrder($order, $dhaka, $admin);
            });

        $demo = OrderFulfilment::query()
            ->where('status', OrderFulfilment::STATUS_AWAITING_PICK)
            ->oldest('id')
            ->first();

        if ($demo && $admin && ! OrderFulfilment::query()->where('status', OrderFulfilment::STATUS_DELIVERED)->exists()) {
            try {
                $fulfilment->completePick($demo, $admin, 'Seeded pick');
                $demo = $demo->fresh();
                $fulfilment->pack($demo, $admin, 'Seeded pack');
                $demo = $demo->fresh();
                $delivery = $fulfilment->dispatch($demo, $admin, [
                    'assigned_to' => $admin->id,
                    'tracking_ref' => 'TRK-SEED-001',
                    'notes' => 'Seeded Phase 5 delivery',
                ]);
                $fulfilment->markDelivered($delivery, $admin, 'Seeded POD');
            } catch (\Throwable) {
                // leave queue as-is
            }
        }
    }
}
