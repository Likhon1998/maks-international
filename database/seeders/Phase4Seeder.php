<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Database\Seeder;

class Phase4Seeder extends Seeder
{
    public function run(): void
    {
        $orderService = app(OrderService::class);
        $admin = User::query()->where('email', 'admin@maksinternational.com')->first();
        $gadgetHub = Shop::query()->where('code', 'SHP-1043')->first();
        $shopUser = User::query()->where('email', 'gadgethub@partner.local')->first();

        if ($gadgetHub && $shopUser && ! Order::query()->where('shop_id', $gadgetHub->id)->where('source', Order::SOURCE_SHOP_PORTAL)->exists()) {
            $products = Product::query()->where('is_published', true)->take(2)->get();
            $lines = $products->map(fn ($p, $i) => [
                'product_id' => $p->id,
                'quantity' => [6, 3][$i] ?? 2,
            ])->all();

            $orderService->placeFromShopPortal(
                $shopUser,
                $gadgetHub,
                $lines,
                'Seeded shop-portal order for Phase 4 audit queue',
            );
        }

        // Approvals that reserve warehouse stock run in Phase5Seeder (after warehouses exist).
    }
}
