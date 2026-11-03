<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogLogoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_admin_can_upload_replace_and_remove_category_and_brand_logos(): void
    {
        Storage::fake('public');
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('categories.index'))
            ->assertOk()
            ->assertSee('Add logo')
            ->assertSee('enctype="multipart/form-data"', false);

        $this->actingAs($admin)
            ->get(route('brands.index'))
            ->assertOk()
            ->assertSee('Add logo');

        $this->actingAs($admin)
            ->post(route('categories.store'), [
                'name' => 'Logo Cat',
                'logo' => UploadedFile::fake()->image('cat.png', 160, 160),
            ])
            ->assertSessionHasNoErrors();

        $category = Category::query()->where('name', 'Logo Cat')->firstOrFail();
        $this->assertNotNull($category->logo_path);
        $this->assertNotNull($category->logoUrl());
        Storage::disk('public')->assertExists($category->logo_path);

        $this->actingAs($admin)
            ->post(route('brands.store'), [
                'name' => 'Logo Brand',
                'logo' => UploadedFile::fake()->image('brand.jpg', 160, 160),
            ])
            ->assertSessionHasNoErrors();

        $brand = Brand::query()->where('name', 'Logo Brand')->firstOrFail();
        $this->assertNotNull($brand->logo_path);
        Storage::disk('public')->assertExists($brand->logo_path);

        $oldCategoryLogo = $category->logo_path;
        $this->actingAs($admin)
            ->put(route('categories.update', $category), [
                'name' => 'Logo Cat',
                'is_active' => 1,
                'logo' => UploadedFile::fake()->image('cat-new.png', 120, 120),
            ])
            ->assertSessionHasNoErrors();
        $category->refresh();
        $this->assertNotSame($oldCategoryLogo, $category->logo_path);
        Storage::disk('public')->assertMissing($oldCategoryLogo);
        Storage::disk('public')->assertExists($category->logo_path);

        $this->actingAs($admin)
            ->put(route('brands.update', $brand), [
                'name' => 'Logo Brand',
                'is_active' => 1,
                'remove_logo' => 1,
            ])
            ->assertSessionHasNoErrors();
        $brand->refresh();
        $this->assertNull($brand->logo_path);

        $this->actingAs($admin)
            ->post(route('categories.store'), [
                'name' => 'Quick Logo Cat',
                'logo' => UploadedFile::fake()->image('quick-cat.png', 80, 80),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('name', 'Quick Logo Cat');
        $this->assertNotNull(Category::query()->where('name', 'Quick Logo Cat')->value('logo_path'));
    }
}
