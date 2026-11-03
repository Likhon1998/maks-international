<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImagesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_multiple_images_can_be_uploaded_on_create(): void
    {
        Storage::fake('public');
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('products.store'), [
                'name' => 'Gallery Camera',
                'sku' => 'CAM-GAL-1',
                'wholesale_price' => 1200,
                'status' => 'active',
                'images' => [
                    UploadedFile::fake()->image('front.jpg', 200, 200),
                    UploadedFile::fake()->image('side.png', 200, 200),
                    UploadedFile::fake()->image('box.jpg', 200, 200),
                ],
            ])
            ->assertSessionHasNoErrors();

        $product = Product::query()->where('sku', 'CAM-GAL-1')->firstOrFail();
        $this->assertCount(3, $product->images);
        $this->assertSame(1, $product->images->where('is_primary', true)->count());
        $this->assertNotNull($product->image_path);
        $this->assertCount(3, $product->imageUrls());

        $this->actingAs($admin)
            ->get(route('products.edit', $product))
            ->assertOk()
            ->assertSee('Add pictures')
            ->assertSee('Delete');
    }

    public function test_admin_can_add_remove_and_change_cover_image(): void
    {
        Storage::fake('public');
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $product = Product::query()->firstOrFail();

        $this->actingAs($admin)
            ->put(route('products.update', $product), [
                'name' => $product->name,
                'sku' => $product->sku,
                'wholesale_price' => $product->wholesale_price,
                'status' => $product->status,
                'images' => [
                    UploadedFile::fake()->image('a.jpg', 180, 180),
                    UploadedFile::fake()->image('b.jpg', 180, 180),
                ],
            ])
            ->assertSessionHasNoErrors();

        $product->refresh()->load('images');
        $this->assertGreaterThanOrEqual(2, $product->images->count());
        $keep = $product->images->firstWhere('is_primary', false) ?? $product->images->last();
        $drop = $product->images->first(fn ($image) => $image->id !== $keep->id);

        $this->actingAs($admin)
            ->put(route('products.update', $product), [
                'name' => $product->name,
                'sku' => $product->sku,
                'wholesale_price' => $product->wholesale_price,
                'status' => $product->status,
                'primary_image_id' => $keep->id,
                'remove_images' => [$drop->id],
            ])
            ->assertSessionHasNoErrors();

        $product->refresh()->load('images');
        $this->assertNull($product->images->firstWhere('id', $drop->id));
        $this->assertTrue((bool) $product->images->firstWhere('id', $keep->id)?->is_primary);
        $this->assertSame($keep->path, $product->image_path);
    }
}
