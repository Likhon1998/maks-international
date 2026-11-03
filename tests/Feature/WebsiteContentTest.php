<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\UserAccessScope;
use App\Support\SiteContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The public website is driven by Admin → Website and live catalogue data. */
class WebsiteContentTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->super = User::where('email', 'admin@maksinternational.com')->firstOrFail();
    }

    /** Current values of a section, as the admin form would post them. */
    private function form(string $section, array $changes = []): array
    {
        $values = collect(app(SiteContent::class)->section($section))
            ->reject(fn ($v, $k) => SiteContent::fields($section)[$k]['type'] === 'image')
            ->map(fn ($v) => is_bool($v) ? (int) $v : $v)
            ->all();

        return array_merge($values, $changes, ['section' => $section]);
    }

    public function test_public_pages_show_defaults_and_live_catalogue(): void
    {
        $category = Category::where('is_active', true)
            ->whereHas('products', fn ($q) => $q->where('status', Product::STATUS_ACTIVE)->where('is_published', true))
            ->firstOrFail();

        $this->get(route('site.home'))->assertOk()
            ->assertSee('Reliable wholesale supply for your retail shop.')
            ->assertSee('Partner shops')
            ->assertSee($category->name);
        $this->get(route('site.about'))->assertOk()->assertSee('Wholesale distribution, built for Bangladesh.');
        $this->get(route('site.contact'))->assertOk()->assertSee('hello@bynnastrade.com');
        $this->get(route('site.partner'))->assertOk()->assertSee('Apply for wholesale access.');
    }

    public function test_admin_edits_flow_through_to_every_public_page(): void
    {
        $this->actingAs($this->super)->get(route('website.edit', ['tab' => 'home']))->assertOk()->assertSee('Hero');

        $this->put(route('website.update'), $this->form('general', [
            'site_name' => 'Bynnas Wholesale', 'email' => 'sales@bynnas.test', 'phone' => '+880 1811-223344',
            'address' => 'Gulshan 2, Dhaka', 'whatsapp' => '01811223344', 'facebook_url' => 'https://facebook.com/bynnas',
        ]))->assertSessionHasNoErrors()->assertRedirect(route('website.edit', ['tab' => 'general']));

        $this->put(route('website.update'), $this->form('home', [
            'hero_title' => 'Wholesale made simple.',
            'hero_points' => ['Fast delivery', '', 'Fair prices'],
            'show_stats' => 0,
            'show_categories' => 0,
            'features' => [['icon' => 'star', 'title' => 'Top brands', 'text' => 'Only genuine stock.'], ['icon' => 'bad', 'title' => '', 'text' => '']],
        ]))->assertSessionHasErrors('features.1.icon');

        $this->put(route('website.update'), $this->form('home', [
            'hero_title' => 'Wholesale made simple.',
            'hero_points' => ['Fast delivery', '', 'Fair prices'],
            'show_stats' => 0,
            'show_categories' => 0,
            'features' => [['icon' => 'star', 'title' => 'Top brands', 'text' => 'Only genuine stock.'], ['icon' => 'tag', 'title' => '', 'text' => '']],
        ]))->assertSessionHasNoErrors();

        $home = app(SiteContent::class)->section('home');
        $this->assertSame(['Fast delivery', 'Fair prices'], $home['hero_points']);
        $this->assertCount(1, $home['features']);

        $this->post(route('logout'));
        $this->get(route('site.home'))->assertOk()
            ->assertSee('Wholesale made simple.')->assertSee('Top brands')->assertSee('Fast delivery')
            ->assertDontSee('Partner shops')->assertDontSee('Doorstep delivery')->assertDontSee('Categories in our catalogue')
            ->assertSee('Bynnas Wholesale')->assertSee('https://facebook.com/bynnas', false);
        $this->get(route('site.contact'))->assertOk()
            ->assertSee('sales@bynnas.test')->assertSee('Gulshan 2, Dhaka')->assertSee('https://wa.me/8801811223344', false);
    }

    public function test_turning_off_partner_applications_closes_the_page_and_form(): void
    {
        $this->actingAs($this->super)->put(route('website.update'), $this->form('general', ['show_partner_button' => 0]))->assertSessionHasNoErrors();
        $this->post(route('logout'));

        $this->get(route('site.home'))->assertOk()->assertDontSee(route('site.partner'), false);
        $this->get(route('site.partner'))->assertRedirect(route('site.contact'));
        $this->post(route('site.partner.store'), [
            'business_name' => 'Closed Shop', 'contact_name' => 'X', 'email' => 'closed@example.com', 'phone' => '01712000111',
        ])->assertRedirect(route('site.contact'));
        $this->assertDatabaseMissing('partner_inquiries', ['email' => 'closed@example.com']);
    }

    public function test_custom_success_messages_are_used(): void
    {
        $this->actingAs($this->super)->put(route('website.update'), $this->form('contact', ['success_message' => 'Got it, talk soon!']))->assertSessionHasNoErrors();
        $this->post(route('logout'));

        $this->from(route('site.contact'))->post(route('site.contact.store'), [
            'name' => 'Visitor', 'email' => 'visitor@example.com', 'message' => 'Hello',
        ])->assertSessionHas('success', 'Got it, talk soon!');
    }

    public function test_logo_and_hero_image_upload_and_reset(): void
    {
        Storage::fake('public');
        $this->actingAs($this->super)->put(route('website.update'), $this->form('general') + [
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
        ])->assertSessionHasNoErrors();

        $logo = app(SiteContent::class)->get('general.logo');
        $this->assertStringStartsWith('site/', $logo);
        Storage::disk('public')->assertExists($logo);
        $this->get(route('dashboard'))->assertOk()->assertSee(route('favicon.png', ['size' => 32]), false);

        $this->put(route('website.update'), $this->form('general', ['remove_images' => ['logo']]))->assertSessionHasNoErrors();
        $this->assertNull(app(SiteContent::class)->get('general.logo'));
        Storage::disk('public')->assertMissing($logo);

        $this->put(route('website.update'), $this->form('about', ['hero_title' => 'Changed about']))->assertSessionHasNoErrors();
        $this->post(route('website.reset'), ['section' => 'about'])->assertRedirect(route('website.edit', ['tab' => 'about']));
        $this->assertFalse(SiteSetting::where('section', 'about')->exists());
        $this->get(route('site.about'))->assertSee('Wholesale distribution, built for Bangladesh.');
    }

    public function test_website_permissions(): void
    {
        $viewer = User::create([
            'name' => 'Viewer', 'email' => 'viewer@bt.test', 'password' => 'Secret12345!', 'portal' => User::PORTAL_ADMIN, 'is_active' => true,
        ]);
        $viewer->givePermissionTo('website.view');
        UserAccessScope::create(['user_id' => $viewer->id, 'scope_type' => UserAccessScope::TYPE_GLOBAL]);

        $this->actingAs($viewer)->get(route('website.edit'))->assertOk()->assertSee('not change it');
        $this->put(route('website.update'), $this->form('home', ['hero_title' => 'Hacked']))->assertForbidden();
        $this->post(route('website.reset'), ['section' => 'home'])->assertForbidden();
        $this->assertNotSame('Hacked', app(SiteContent::class)->get('home.hero_title'));

        $this->actingAs($this->super)->put(route('website.update'), ['section' => 'nope'])->assertNotFound();
        $this->put(route('website.update'), $this->form('home', ['hero_title' => '']))->assertSessionHasErrors('hero_title');
        $this->put(route('website.update'), $this->form('general', ['email' => 'not-an-email', 'facebook_url' => 'javascript:alert(1)']))
            ->assertSessionHasErrors(['email', 'facebook_url']);
    }
}
