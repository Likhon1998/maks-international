<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogQuickCreateTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_product_form_exposes_quick_add_options(): void
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('products.create'))
            ->assertOk()
            ->assertSee('+ Add new category')
            ->assertSee('+ Add new brand')
            ->assertSee('+ New')
            ->assertSee('quickCatalogSelect');
    }

    public function test_json_create_category_and_brand_persists(): void
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();

        $this->actingAs($admin)
            ->postJson(route('categories.store'), ['name' => 'Quick Cat'])
            ->assertOk()
            ->assertJsonPath('name', 'Quick Cat');

        $this->assertDatabaseHas('categories', ['name' => 'Quick Cat']);

        $this->actingAs($admin)
            ->postJson(route('brands.store'), ['name' => 'Quick Brand'])
            ->assertOk()
            ->assertJsonPath('name', 'Quick Brand');

        $this->assertDatabaseHas('brands', ['name' => 'Quick Brand']);

        $this->actingAs($admin)
            ->get(route('products.create'))
            ->assertOk()
            ->assertSee('Quick Cat')
            ->assertSee('Quick Brand');
    }

    public function test_admin_can_edit_and_delete_unused_category_and_brand(): void
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $category = Category::query()->create(['name' => 'Edit Me', 'slug' => 'edit-me', 'is_active' => true]);
        $brand = Brand::query()->create(['name' => 'Edit Brand', 'slug' => 'edit-brand', 'is_active' => true]);

        $this->actingAs($admin)
            ->put(route('categories.update', $category), ['name' => 'Edited Cat', 'is_active' => 1])
            ->assertSessionHasNoErrors();
        $this->assertSame('Edited Cat', $category->fresh()->name);

        $this->actingAs($admin)
            ->put(route('brands.update', $brand), ['name' => 'Edited Brand', 'is_active' => 1])
            ->assertSessionHasNoErrors();
        $this->assertSame('Edited Brand', $brand->fresh()->name);

        $this->actingAs($admin)->delete(route('categories.destroy', $category))->assertSessionHasNoErrors();
        $this->actingAs($admin)->delete(route('brands.destroy', $brand))->assertSessionHasNoErrors();

        $this->assertSoftDeleted($category);
        $this->assertSoftDeleted($brand);
    }

    public function test_soft_deleted_category_and_brand_can_be_recreated(): void
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $category = Category::query()->create(['name' => 'Revived Cat', 'slug' => 'revived-cat', 'is_active' => true]);
        $brand = Brand::query()->create(['name' => 'Revived Brand', 'slug' => 'revived-brand', 'is_active' => true]);
        $category->delete();
        $brand->delete();

        $this->actingAs($admin)
            ->postJson(route('categories.store'), ['name' => 'Revived Cat'])
            ->assertOk()
            ->assertJsonPath('id', $category->id);

        $this->actingAs($admin)
            ->postJson(route('brands.store'), ['name' => 'Revived Brand'])
            ->assertOk()
            ->assertJsonPath('id', $brand->id);

        $this->assertNotSoftDeleted($category);
        $this->assertNotSoftDeleted($brand);
    }
}
