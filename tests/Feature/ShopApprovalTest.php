<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_salesman_new_shop_is_active_and_ready_for_orders(): void
    {
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();

        $this->actingAs($salesman)->post(route('field.shops.store'), [
            'name' => 'Ready Shop',
            'owner_name' => 'Owner',
            'phone' => '01811223344',
            'city' => 'Dhaka',
        ])->assertSessionHasNoErrors();

        $shop = Shop::where('name', 'Ready Shop')->firstOrFail();
        $this->assertSame(Shop::STATUS_ACTIVE, $shop->status);
        $this->assertNotNull($shop->approved_at);
        $this->assertSame($salesman->id, $shop->assigned_salesman_id);
        $this->assertGreaterThan(0, (float) $shop->credit_limit);
    }

    public function test_add_shop_form_uses_bangladesh_mobile_field(): void
    {
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();

        $this->actingAs($salesman)
            ->get(route('field.shops.create'))
            ->assertOk()
            ->assertSee('+880', false)
            ->assertSee('1712-345678', false)
            ->assertSee('inputmode="numeric"', false)
            ->assertDontSee('01234534yr', false);
    }

    public function test_add_shop_form_uses_district_thana_post_office_and_location(): void
    {
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();

        $this->actingAs($salesman)
            ->get(route('field.shops.create'))
            ->assertOk()
            ->assertSee('Division', false)
            ->assertSee('District', false)
            ->assertSee('Thana', false)
            ->assertSee('Post office', false)
            ->assertSee('Location', false)
            ->assertSee('Add a new location', false)
            ->assertSee('f-combo-sheet-head', false)
            ->assertSee('Search division', false);

        $this->actingAs($salesman)->post(route('field.shops.store'), [
            'name' => 'Mirpur New Shop',
            'owner_name' => 'Owner',
            'phone' => '01811220011',
            'division' => 'Dhaka',
            'district' => 'Dhaka',
            'thana' => 'Mirpur',
            'post_office' => 'Mirpur',
            'location' => 'Mirpur 10',
        ])->assertSessionHasNoErrors();

        $shop = Shop::where('name', 'Mirpur New Shop')->firstOrFail();
        $this->assertSame('Dhaka', $shop->division);
        $this->assertSame('Dhaka', $shop->district);
        $this->assertSame('Mirpur', $shop->thana);
        $this->assertSame('Mirpur', $shop->city);
        $this->assertSame('Mirpur 10', $shop->location);
    }

    public function test_salesman_shop_phone_rejects_letters_and_junk(): void
    {
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();

        $this->actingAs($salesman)->post(route('field.shops.store'), [
            'name' => 'Bad Phone Shop',
            'owner_name' => 'Owner',
            'phone' => '01234534yr3ury38ry38',
            'city' => 'Dhaka',
        ])->assertSessionHasErrors('phone');
    }

    public function test_salesman_can_approve_existing_pending_shop(): void
    {
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $shop = Shop::query()->create([
            'code' => 'SHP-TEST1',
            'name' => 'Pending Field Shop',
            'owner_name' => 'Owner',
            'phone' => '01855667788',
            'status' => Shop::STATUS_PENDING,
            'assigned_salesman_id' => $salesman->id,
            'created_by' => $salesman->id,
            'credit_limit' => 50000,
        ]);

        $this->actingAs($salesman)
            ->post(route('field.shops.approve', $shop))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(Shop::STATUS_ACTIVE, $shop->fresh()->status);
        $this->assertNotNull($shop->fresh()->approved_at);
    }

    public function test_admin_can_approve_and_deactivate_shop(): void
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $shop = Shop::query()->create([
            'code' => 'SHP-TEST2',
            'name' => 'Admin Pending Shop',
            'owner_name' => 'Owner',
            'phone' => '01899887766',
            'status' => Shop::STATUS_PENDING,
            'credit_limit' => 100000,
        ]);

        $this->actingAs($admin)
            ->get(route('shops.show', $shop))
            ->assertOk()
            ->assertSee('Approve shop');

        $this->actingAs($admin)
            ->post(route('shops.approve', $shop))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(Shop::STATUS_ACTIVE, $shop->fresh()->status);

        $this->actingAs($admin)
            ->post(route('shops.deactivate', $shop))
            ->assertSessionHasNoErrors();

        $this->assertSame(Shop::STATUS_ON_HOLD, $shop->fresh()->status);

        $this->actingAs($admin)
            ->post(route('shops.approve', $shop))
            ->assertSessionHasNoErrors();

        $this->assertSame(Shop::STATUS_ACTIVE, $shop->fresh()->status);
    }

    public function test_salesman_can_add_shop_with_owner_login_in_one_step(): void
    {
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();

        $this->actingAs($salesman)->post(route('field.shops.store'), [
            'name' => 'Login Ready Shop',
            'owner_name' => 'Owner Mia',
            'phone' => '01822334455',
            'city' => 'Dhaka',
            'create_login' => '1',
            'login_email' => 'mia.owner@example.com',
            'login_password' => 'MiaShop2026',
        ])->assertSessionHasNoErrors()
            ->assertRedirect();

        $shop = Shop::where('name', 'Login Ready Shop')->firstOrFail();
        $this->assertSame(Shop::STATUS_ACTIVE, $shop->status);
        $this->assertSame('mia.owner@example.com', $shop->email);
        $this->assertTrue($shop->users()->where('email', 'mia.owner@example.com')->exists());

        $owner = User::where('email', 'mia.owner@example.com')->firstOrFail();
        $this->assertSame(User::PORTAL_SHOP, $owner->portal);
        $this->assertTrue($owner->is_active);
    }

    public function test_salesman_can_add_login_to_existing_shop(): void
    {
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $shop = Shop::query()->create([
            'code' => 'SHP-LOGIN1',
            'name' => 'Needs Login Shop',
            'owner_name' => 'Rafiq',
            'phone' => '01833445566',
            'status' => Shop::STATUS_ACTIVE,
            'assigned_salesman_id' => $salesman->id,
            'created_by' => $salesman->id,
            'approved_at' => now(),
            'credit_limit' => 50000,
        ]);

        $this->actingAs($salesman)
            ->get(route('field.shops.login', $shop))
            ->assertOk()
            ->assertSee('Set owner login');

        $this->post(route('field.shops.login.store', $shop), [
            'login_email' => 'rafiq.shop@example.com',
            'login_password' => 'Rafiq2026!',
        ])->assertSessionHasNoErrors()
            ->assertRedirect(route('field.shops.login', $shop));

        $this->assertTrue($shop->fresh()->users()->where('email', 'rafiq.shop@example.com')->exists());
    }

    public function test_salesman_cannot_approve_another_salesmans_shop(): void
    {
        $karim = User::where('email', 'karim@field.local')->firstOrFail();
        $other = User::where('portal', User::PORTAL_SALESMAN)->where('id', '!=', $karim->id)->first()
            ?? User::factory()->create(['portal' => User::PORTAL_SALESMAN]);

        // If factory missing, create a minimal other salesman via query
        if (! $other->exists || $other->portal !== User::PORTAL_SALESMAN) {
            $other = User::query()->create([
                'name' => 'Other Salesman',
                'email' => 'other.field@test.local',
                'password' => 'Secret12345!',
                'portal' => User::PORTAL_SALESMAN,
                'is_active' => true,
            ]);
            $other->assignRole('Salesman');
        }

        $shop = Shop::query()->create([
            'code' => 'SHP-TEST3',
            'name' => 'Karim Only Shop',
            'owner_name' => 'Owner',
            'phone' => '01800112233',
            'status' => Shop::STATUS_PENDING,
            'assigned_salesman_id' => $karim->id,
            'created_by' => $karim->id,
        ]);

        $this->actingAs($other)
            ->post(route('field.shops.approve', $shop))
            ->assertForbidden();

        $this->assertSame(Shop::STATUS_PENDING, $shop->fresh()->status);
    }
}
