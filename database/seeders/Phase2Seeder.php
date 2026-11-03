<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\PriceGroup;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Territory;
use App\Models\User;
use App\Services\ProductService;
use App\Services\ShopService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class Phase2Seeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('email', 'admin@maksinternational.com')->first();

        $territories = collect([
            ['name' => 'Dhaka North', 'code' => 'DHK-N'],
            ['name' => 'Chattogram', 'code' => 'CTG'],
            ['name' => 'Khulna & Barishal', 'code' => 'KHL'],
            ['name' => 'Sylhet', 'code' => 'SYL'],
        ])->map(fn ($row) => Territory::query()->updateOrCreate(['code' => $row['code']], $row + ['is_active' => true]));

        $standard = PriceGroup::query()->updateOrCreate(
            ['code' => 'STD'],
            ['name' => 'Standard wholesale', 'description' => 'Default shop pricing', 'is_default' => true, 'is_active' => true],
        );
        $preferred = PriceGroup::query()->updateOrCreate(
            ['code' => 'PREF'],
            ['name' => 'Preferred partner', 'description' => 'Volume partner pricing', 'is_default' => false, 'is_active' => true],
        );

        $categories = collect(['Audio', 'Power', 'Wearables', 'Accessories'])
            ->map(fn ($name) => Category::query()->updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'is_active' => true],
            ));

        $brand = Brand::query()->updateOrCreate(
            ['slug' => 'bynnas'],
            ['name' => 'Bynnas', 'is_active' => true],
        );

        $productService = app(ProductService::class);

        $catalog = [
            ['sku' => 'BT-EB-01', 'name' => 'Bluetooth Earbuds', 'category' => 'Audio', 'stock' => 1245, 'wholesale' => 1250, 'landed' => 780, 'pref' => 1180, 'image' => 'products/bt-eb-01.jpg'],
            ['sku' => 'PW-BK-20', 'name' => 'Power Bank 20,000 mAh', 'category' => 'Power', 'stock' => 860, 'wholesale' => 1850, 'landed' => 1120, 'pref' => 1750, 'image' => 'products/pw-bk-20.jpg'],
            ['sku' => 'SM-WT-09', 'name' => 'Smart Watch Series 9', 'category' => 'Wearables', 'stock' => 214, 'wholesale' => 4200, 'landed' => 2650, 'pref' => 3990, 'image' => 'products/sm-wt-09.jpg'],
            ['sku' => 'CG-CH-33', 'name' => 'USB-C Fast Charger 33W', 'category' => 'Accessories', 'stock' => 2104, 'wholesale' => 450, 'landed' => 210, 'pref' => 420, 'image' => 'products/cg-ch-33.jpg'],
            ['sku' => 'SP-BT-12', 'name' => 'Portable Bluetooth Speaker', 'category' => 'Audio', 'stock' => 532, 'wholesale' => 2650, 'landed' => 1580, 'pref' => 2490, 'image' => 'products/sp-bt-12.jpg'],
        ];

        foreach ($catalog as $row) {
            $category = $categories->firstWhere('name', $row['category']);
            $product = Product::query()->where('sku', $row['sku'])->first();

            $payload = [
                'name' => $row['name'],
                'sku' => $row['sku'],
                'category_id' => $category?->id,
                'brand_id' => $brand->id,
                'wholesale_price' => $row['wholesale'],
                'dealer_price' => $row['wholesale'] * 1.08,
                'distributor_price' => $row['wholesale'] * 0.95,
                'retail_price' => $row['wholesale'] * 1.35,
                'minimum_selling_price' => $row['wholesale'] * 0.9,
                'cost_price' => $row['landed'] * 0.85,
                'landed_cost' => $row['landed'],
                'stock_on_hand' => $row['stock'],
                'minimum_stock' => 20,
                'warranty' => '12 months',
                'status' => 'active',
                'is_published' => true,
            ];

            if (! empty($row['image']) && is_file(storage_path('app/public/'.$row['image']))) {
                $payload['image_path'] = $row['image'];
            }

            if ($product) {
                $productService->update($product, $payload, $admin, [
                    $preferred->id => $row['pref'],
                ]);
            } else {
                $productService->create($payload, $admin, [
                    $preferred->id => $row['pref'],
                ]);
            }
        }

        $shopService = app(ShopService::class);

        $existing = Shop::query()->where('code', 'SHP-1042')->first();
        if (! $existing) {
            $shopService->create([
                'code' => 'SHP-1042',
                'name' => 'Tech Zone',
                'owner_name' => 'Rafiq Hasan',
                'phone' => '01711223344',
                'email' => 'techzone@partner.local',
                'address' => 'Elephant Road, Dhaka',
                'city' => 'Dhaka',
                'territory_id' => $territories->firstWhere('code', 'DHK-N')?->id,
                'price_group_id' => $preferred->id,
                'credit_limit' => 250000,
                'payment_terms_days' => 21,
                'status' => Shop::STATUS_ACTIVE,
            ], $admin, [
                'email' => 'techzone@partner.local',
                'password' => '12345678',
                'name' => 'Rafiq Hasan',
                'phone' => '01711223344',
            ]);
        }

        $existing2 = Shop::query()->where('code', 'SHP-1043')->first();
        if (! $existing2) {
            $shopService->create([
                'code' => 'SHP-1043',
                'name' => 'Gadget Hub',
                'owner_name' => 'Nusrat Jahan',
                'phone' => '01819887766',
                'email' => 'gadgethub@partner.local',
                'address' => 'Agrabad, Chattogram',
                'city' => 'Chattogram',
                'territory_id' => $territories->firstWhere('code', 'CTG')?->id,
                'price_group_id' => $standard->id,
                'credit_limit' => 180000,
                'payment_terms_days' => 14,
                'status' => Shop::STATUS_ACTIVE,
            ], $admin, [
                'email' => 'gadgethub@partner.local',
                'password' => '12345678',
                'name' => 'Nusrat Jahan',
            ]);
        }
    }
}
