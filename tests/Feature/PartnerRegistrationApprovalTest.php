<?php

namespace Tests\Feature;

use App\Models\PartnerInquiry;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerRegistrationApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_admin_can_see_approve_and_activate_website_partner_registration(): void
    {
        $this->post(route('site.partner.store'), [
            'business_name' => 'New Corner Shop',
            'contact_name' => 'Owner Ali',
            'email' => 'ali.corner@example.com',
            'phone' => '01677889900',
            'city' => 'Dhaka',
            'business_type' => 'retailer',
            'message' => 'Please approve our wholesale account.',
        ])->assertSessionHasNoErrors()
            ->assertRedirect(route('site.partner'));

        $inquiry = PartnerInquiry::query()->where('email', 'ali.corner@example.com')->firstOrFail();
        $this->assertTrue($inquiry->isPending());

        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('partner-inquiries.index', ['status' => 'pending']))
            ->assertOk()
            ->assertSee('New Corner Shop')
            ->assertSee('Approve')
            ->assertSee(route('partner-inquiries.accept', $inquiry), false);

        $response = $this->actingAs($admin)
            ->post(route('partner-inquiries.accept', $inquiry));

        $this->assertNull(session('error'), 'Accept failed: '.(string) session('error'));
        $response->assertSessionHasNoErrors()->assertSessionHas('success');

        $inquiry->refresh();
        $this->assertTrue($inquiry->isAccepted());
        $this->assertNotNull($inquiry->shop_id);

        $shop = Shop::query()->findOrFail($inquiry->shop_id);
        $this->assertSame(Shop::STATUS_ACTIVE, $shop->status);
        $this->assertSame('ali.corner@example.com', $shop->email);
        $this->assertTrue($shop->users()->where('email', 'ali.corner@example.com')->exists());
    }

    public function test_admin_can_approve_registration_even_when_phone_matches_existing_shop(): void
    {
        $existing = Shop::query()->create([
            'code' => 'SHP-DUP1',
            'name' => 'Existing Phone Shop',
            'owner_name' => 'Old Owner',
            'phone' => '01655667788',
            'status' => Shop::STATUS_PENDING,
            'credit_limit' => 50000,
        ]);

        $inquiry = PartnerInquiry::query()->create([
            'business_name' => 'Existing Phone Shop',
            'contact_name' => 'New Owner',
            'email' => 'new.owner.dup@example.com',
            'phone' => '01655667788',
            'city' => 'Dhaka',
            'status' => PartnerInquiry::STATUS_NEW,
            'source' => PartnerInquiry::SOURCE_WEBSITE,
        ]);

        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('partner-inquiries.accept', $inquiry))
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('error')
            ->assertSessionHas('success');

        $this->assertSame(Shop::STATUS_ACTIVE, $existing->fresh()->status);
        $this->assertSame($existing->id, $inquiry->fresh()->shop_id);
        $this->assertTrue($existing->fresh()->users()->where('email', 'new.owner.dup@example.com')->exists());
    }

    public function test_admin_without_approve_permission_does_not_see_approve_button(): void
    {
        PartnerInquiry::query()->create([
            'business_name' => 'Hidden Approve Shop',
            'contact_name' => 'Owner',
            'email' => 'hidden.approve@example.com',
            'phone' => '01799887766',
            'city' => 'Chittagong',
            'status' => PartnerInquiry::STATUS_NEW,
            'source' => PartnerInquiry::SOURCE_WEBSITE,
        ]);

        $viewer = User::factory()->create([
            'portal' => User::PORTAL_ADMIN,
            'is_active' => true,
        ]);
        $viewer->givePermissionTo('shops.view');

        $this->actingAs($viewer)
            ->get(route('partner-inquiries.index', ['status' => 'pending']))
            ->assertOk()
            ->assertSee('Hidden Approve Shop')
            ->assertDontSee('>Approve</button>', false);
    }
}
