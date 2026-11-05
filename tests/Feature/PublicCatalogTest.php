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

    public function test_visitors_see_every_product_and_order_opens_maksgadget(): void
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
            ->assertSee('https://maksgadget.com', false)
            ->assertDontSee('https://makgadget.com', false);

        $this->get(route('site.products'))
            ->assertOk()
            ->assertSee('All products')
            ->assertSee($hidden->name)
            ->assertSee(Product::query()->first()->name)
            ->assertSee('https://maksgadget.com', false)
            ->assertSee('Order')
            ->assertDontSee('https://makgadget.com', false);

        $this->get(route('site.products.show', $hidden))
            ->assertOk()
            ->assertSee($hidden->name)
            ->assertSee('Order on MAKS GADGET')
            ->assertSee('https://maksgadget.com', false);

        $this->assertSame(Product::query()->count(), app(SiteContent::class)->catalogProducts()->count());
    }

    public function test_admin_can_change_the_external_order_url(): void
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $values = collect(app(SiteContent::class)->section('general'))
            ->reject(fn ($value, $key) => SiteContent::fields('general')[$key]['type'] === 'image')
            ->map(fn ($value) => is_bool($value) ? (int) $value : $value)
            ->all();

        $values['maks_gadget_url'] = 'https://maksgadget.com/shop';
        $this->actingAs($admin)
            ->put(route('website.update'), $values + ['section' => 'general'])
            ->assertSessionHasNoErrors();

        $this->post(route('logout'));
        $this->get(route('site.products'))
            ->assertOk()
            ->assertSee('https://maksgadget.com/shop', false);
    }
}
