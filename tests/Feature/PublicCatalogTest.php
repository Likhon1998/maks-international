<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Support\SiteContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_visitors_see_every_product_and_order_goes_to_maks_gadget(): void
    {
        $hidden = Product::query()->create([
            'name' => 'Visitor Hidden Phone',
            'sku' => 'VIS-HIDE-1',
            'wholesale_price' => 500,
            'retail_price' => 799,
            'status' => Product::STATUS_INACTIVE,
            'is_published' => false,
        ]);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('Products')
            ->assertSee('View products')
            ->assertSee('https://makgadget.com/shop', false);

        $this->get(route('site.products'))
            ->assertOk()
            ->assertSee('All products')
            ->assertSee($hidden->name)
            ->assertSee(Product::query()->first()->name)
            ->assertSee('https://makgadget.com/shop', false)
            ->assertSee('Order');

        $this->get(route('site.products.show', $hidden))
            ->assertOk()
            ->assertSee($hidden->name)
            ->assertSee('Order on MAKS GADGET')
            ->assertSee('https://makgadget.com/shop', false);

        $this->assertSame(Product::query()->count(), app(SiteContent::class)->catalogProducts()->count());
    }

    public function test_admin_can_change_the_maks_gadget_order_url(): void
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $values = collect(app(SiteContent::class)->section('general'))
            ->reject(fn ($value, $key) => SiteContent::fields('general')[$key]['type'] === 'image')
            ->map(fn ($value) => is_bool($value) ? (int) $value : $value)
            ->all();

        $this->actingAs($admin)
            ->put(route('website.update'), $values + [
                'section' => 'general',
                'maks_gadget_url' => 'https://makgadget.com/',
            ])
            ->assertSessionHasNoErrors();

        $this->post(route('logout'));
        $this->get(route('site.products'))
            ->assertOk()
            ->assertSee('https://makgadget.com/', false);
    }
}
