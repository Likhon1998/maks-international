<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Shop;
use App\Models\ShopVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** @return list<array{0:string,1:string}> [method, uri] with route params filled from seeded data */
    private function routes(string $prefix): array
    {
        $tables = [
            'productReturn' => 'returns', 'visit' => 'shop_visits', 'target' => 'sales_targets',
            'priceGroup' => 'price_groups', 'partnerInquiry' => 'partner_inquiries', 'contactMessage' => 'contact_messages',
            'fulfilment' => 'order_fulfilments', 'id' => 'notifications',
            'offer' => 'product_offers', 'announcement' => 'announcements', 'brand' => 'brands',
        ];
        $value = function (string $name) use ($tables) {
            return match ($name) {
                'report' => 'sales',
                'salesman' => User::where('portal', 'salesman')->value('id'),
                'offer' => ProductOffer::query()->value('id') ?? ProductOffer::query()->create([
                    'product_id' => Product::query()->value('id'),
                    'title' => 'ACL stub',
                    'offer_price' => 1,
                    'is_active' => false,
                ])->id,
                'announcement' => Announcement::query()->value('id') ?? Announcement::query()->create([
                    'title' => 'ACL stub',
                    'body' => 'stub',
                    'send_to_all' => true,
                    'is_active' => false,
                    'published_at' => now(),
                ])->id,
                default => DB::table($tables[$name] ?? Str::snake(Str::plural($name)))->value('id') ?? 1,
            };
        };

        $out = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! Str::startsWith($uri, $prefix) || Str::endsWith($uri, ['login', 'logout'])) {
                continue;
            }
            foreach ($route->parameterNames() as $p) {
                $uri = str_replace(['{'.$p.'}', '{'.$p.'?}'], (string) $value($p), $uri);
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $out[] = [$method, '/'.$uri];
            }
        }

        return $out;
    }

    public function test_admin_without_permissions_is_refused_everywhere(): void
    {
        $nobody = User::create([
            'name' => 'Nobody', 'email' => 'nobody@bt.test', 'password' => '12345678',
            'portal' => User::PORTAL_ADMIN, 'is_active' => true,
        ]);
        $allowed = ['/admin', '/admin/dashboard', '/admin/notifications', '/admin/notifications/read-all', '/admin/account/password'];

        $leaks = [];
        foreach ($this->routes('admin') as [$method, $uri]) {
            if (in_array($uri, $allowed, true) || Str::startsWith($uri, ['/admin/notifications/', '/admin/fulfilment/1'])
                && ! Str::contains($uri, ['pick', 'pack', 'dispatch', 'deliver', 'workspace'])) {
                continue;
            }
            $status = $this->actingAs($nobody)->call($method, $uri)->status();
            if ($status !== 403) {
                $leaks[] = "{$method} {$uri} => {$status}";
            }
        }

        $this->assertSame([], $leaks, "Reachable without permission:\n".implode("\n", $leaks));

        $this->actingAs($nobody)->get('/admin/dashboard')
            ->assertOk()
            ->assertSee('No modules assigned yet')
            ->assertDontSee('Sales Overview')
            ->assertDontSee('Quick Actions');
    }

    public function test_guests_are_sent_to_login(): void
    {
        foreach (['admin' => '/admin/login', 'portal' => '/portal/login', 'field' => '/field/login'] as $prefix => $login) {
            foreach ($this->routes($prefix) as [$method, $uri]) {
                if ($method !== 'GET') {
                    continue;
                }
                $response = $this->call('GET', $uri);
                $this->assertTrue($response->isRedirect(), "{$uri} should redirect guests, got {$response->status()}");
                $this->assertStringEndsWith($login, (string) $response->headers->get('Location'), "{$uri} should go to {$login}");
            }
        }
    }

    public function test_portals_are_isolated(): void
    {
        $shopUser = User::where('email', 'techzone@partner.local')->firstOrFail();
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();

        foreach ([$shopUser, $salesman] as $user) {
            $this->actingAs($user)->get('/admin/dashboard')->assertRedirect(route('login'));
            $this->assertGuest();
        }

        $this->actingAs($salesman)->get('/portal/dashboard')->assertRedirect();
        $this->actingAs($shopUser)->get('/field/dashboard')->assertRedirect();
        $this->actingAs($admin)->get('/portal/dashboard')->assertRedirect();
    }

    public function test_wrong_portal_login_points_to_the_right_portal(): void
    {
        $this->from(route('login'))
            ->post(route('login.submit'), ['email' => 'sabrina@field.local', 'password' => '12345678'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email')
            ->assertSessionHas('portal_hint.url', route('field.login', ['email' => 'sabrina@field.local']));
        $this->assertGuest();

        $this->get(route('field.login', ['email' => 'sabrina@field.local']))->assertOk()->assertSee('value="sabrina@field.local"', false);
        $this->post(route('field.login.submit'), ['email' => 'sabrina@field.local', 'password' => '12345678'])
            ->assertRedirect(route('field.dashboard'));
        $this->post(route('field.logout'));

        $this->from(route('field.login'))
            ->post(route('field.login.submit'), ['email' => 'techzone@partner.local', 'password' => '12345678'])
            ->assertSessionHas('portal_hint.url', route('portal.login', ['email' => 'techzone@partner.local']));

        $this->from(route('portal.login'))
            ->post(route('portal.login.submit'), ['email' => 'admin@maksinternational.com', 'password' => '12345678'])
            ->assertSessionHas('portal_hint.label', 'Admin portal');
        $this->assertGuest();
    }

    public function test_shop_cannot_touch_another_shops_orders(): void
    {
        $shopUser = User::where('email', 'techzone@partner.local')->firstOrFail();
        $ownShopId = $shopUser->primaryShop()->id;
        $foreign = Order::where('shop_id', '!=', $ownShopId)->firstOrFail();

        $this->actingAs($shopUser);
        $this->get(route('portal.orders.show', $foreign))->assertNotFound();
        $this->get(route('portal.orders.edit', $foreign))->assertNotFound();
        $this->put(route('portal.orders.update', $foreign), ['items' => []])->assertNotFound();
        $this->delete(route('portal.orders.destroy', $foreign))->assertNotFound();
        $this->post(route('portal.orders.pay', $foreign), ['amount' => 10, 'method' => 'cash'])->assertNotFound();
        $this->assertNotNull(Order::find($foreign->id));
    }

    public function test_salesman_is_limited_to_own_shops_visits_and_orders(): void
    {
        $karim = User::where('email', 'karim@field.local')->firstOrFail();
        $other = User::where('portal', User::PORTAL_SALESMAN)->where('id', '!=', $karim->id)->firstOrFail();
        $otherVisit = ShopVisit::firstOrCreate(
            ['salesman_id' => $other->id, 'shop_id' => Shop::value('id')],
            ['checked_in_at' => now()],
        );
        $foreignShop = Shop::where(fn ($q) => $q->whereNull('assigned_salesman_id')->orWhere('assigned_salesman_id', '!=', $karim->id))->firstOrFail();

        $this->actingAs($karim);
        $this->get(route('field.visit.show', $otherVisit))->assertForbidden();
        $this->post(route('field.visit.checkout', $otherVisit), ['outcome' => 'no_order'])->assertForbidden();
        $this->post(route('field.visit.order', $otherVisit), ['items' => []])->assertForbidden();
        $this->post(route('field.shops.check-in', $foreignShop))->assertSessionHasErrors('shop');

        $foreignOrder = Order::where(fn ($q) => $q->whereNull('salesman_id')->orWhere('salesman_id', '!=', $karim->id))->first();
        if ($foreignOrder) {
            $this->assertContains($this->get(route('field.orders.show', $foreignOrder))->status(), [403, 404]);
        }
    }

    public function test_login_is_rate_limited(): void
    {
        foreach (range(1, 5) as $i) {
            $this->post(route('login.submit'), ['email' => 'admin@maksinternational.com', 'password' => 'wrong-'.$i])
                ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
        }

        $this->post(route('login.submit'), ['email' => 'admin@maksinternational.com', 'password' => '12345678'])
            ->assertSessionHasErrors(['email' => 'Too many login attempts. Please wait a minute and try again.']);
        $this->assertGuest();
    }
}
