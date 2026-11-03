<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Favicon;
use App\Support\SiteContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FaviconTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_tab_icon_is_generated_from_the_brand_logo(): void
    {
        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee(route('favicon.ico'), false)
            ->assertSee(route('favicon.png', ['size' => 32]), false);

        $png = $this->get(route('favicon.png', ['size' => 32]));
        $png->assertOk();
        $this->assertStringContainsString('image/png', (string) $png->headers->get('content-type'));
        $this->assertNotSame('', $png->getContent());
        $info = getimagesizefromstring($png->getContent());
        $this->assertSame(32, $info[0]);
        $this->assertSame(32, $info[1]);

        $this->get('/favicon.ico')->assertOk();
    }

    public function test_uploaded_website_logo_becomes_the_favicon(): void
    {
        Storage::fake('public');
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $values = collect(app(SiteContent::class)->section('general'))
            ->reject(fn ($value, $key) => SiteContent::fields('general')[$key]['type'] === 'image')
            ->map(fn ($value) => is_bool($value) ? (int) $value : $value)
            ->all();

        $this->actingAs($admin)
            ->put(route('website.update'), $values + [
                'section' => 'general',
                'logo' => UploadedFile::fake()->image('maks-logo.png', 240, 240),
            ])
            ->assertSessionHasNoErrors();

        $path = app(SiteContent::class)->get('general.logo');
        $this->assertNotNull($path);
        $this->assertSame(Storage::disk('public')->path($path), Favicon::sourcePath());

        $this->get(route('favicon.png', ['size' => 32]))
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
    }
}
