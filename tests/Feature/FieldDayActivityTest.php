<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\ShopVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FieldDayActivityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_adding_a_shop_is_not_a_visit_and_dashboard_keeps_them_apart(): void
    {
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();
        ShopVisit::query()
            ->where('salesman_id', $salesman->id)
            ->whereNull('checked_out_at')
            ->update(['checked_out_at' => now()]);

        $this->actingAs($salesman)->post(route('field.shops.store'), [
            'name' => 'New Corner Mart',
            'owner_name' => 'Owner',
            'phone' => '01811990011',
            'notes' => 'Owner asked about wireless earbuds.',
        ])->assertSessionHasNoErrors();

        $shop = Shop::where('name', 'New Corner Mart')->firstOrFail();
        $this->assertSame($salesman->id, $shop->created_by);
        $this->assertFalse(
            ShopVisit::query()->where('shop_id', $shop->id)->exists(),
            'Saving a shop must not count as a visit.'
        );

        $this->actingAs($salesman)
            ->get(route('field.dashboard'))
            ->assertOk()
            ->assertSee('New shops today')
            ->assertSee('Visits today')
            ->assertSee('Assigned shops')
            ->assertSee('New Corner Mart')
            ->assertSee('Owner asked about wireless earbuds.')
            ->assertSee('Shops visited each day', false)
            ->assertSee('Visit log', false);
    }

    public function test_assigned_shop_uses_visit_and_leave_and_counts_in_daily_log(): void
    {
        Storage::fake('public');
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $shop = Shop::query()
            ->where('assigned_salesman_id', $salesman->id)
            ->where('status', Shop::STATUS_ACTIVE)
            ->firstOrFail();

        ShopVisit::query()
            ->where('salesman_id', $salesman->id)
            ->whereNull('checked_out_at')
            ->update(['checked_out_at' => now()]);

        $this->actingAs($salesman)
            ->get(route('field.shops'))
            ->assertOk()
            ->assertSee(route('field.shops.check-in', $shop), false)
            ->assertSee('Shop visit', false)
            ->assertSee(route('field.partners'), false)
            ->assertSee(route('field.announcements'), false);

        $this->actingAs($salesman)
            ->post(route('field.shops.check-in', $shop), ['purpose' => 'Shop visit'])
            ->assertRedirect();

        $visit = ShopVisit::query()
            ->where('salesman_id', $salesman->id)
            ->where('shop_id', $shop->id)
            ->whereNull('checked_out_at')
            ->firstOrFail();
        $this->assertSame('Shop visit', $visit->purpose);

        $this->actingAs($salesman)
            ->get(route('field.shops'))
            ->assertOk()
            ->assertSee("I'm leaving")
            ->assertSee('Continue visit');

        $this->actingAs($salesman)
            ->post(route('field.visit.leave', $visit), [
                'outcome' => ShopVisit::OUTCOME_NO_ORDER,
                'redirect' => 'dashboard',
                'notes' => 'Checked stock, owner will order later.',
                'photo' => UploadedFile::fake()->image('old-shop.jpg', 640, 480),
            ])
            ->assertRedirect(route('field.dashboard'));

        $this->actingAs($salesman)
            ->get(route('field.visits'))
            ->assertOk()
            ->assertSee('Visit log')
            ->assertSee($shop->name)
            ->assertSee('1 shop')
            ->assertSee('Checked stock, owner will order later.')
            ->assertSee('From', false)
            ->assertSee('To', false);

        $this->actingAs($salesman)
            ->get(route('field.visits', ['from' => today()->toDateString(), 'to' => today()->toDateString()]))
            ->assertOk()
            ->assertSee($shop->name);

        $this->actingAs($salesman)
            ->get(route('field.visits', [
                'from' => '2020-01-01',
                'to' => '2020-01-02',
            ]))
            ->assertOk()
            ->assertDontSee('Checked stock, owner will order later.')
            ->assertSee('No visits in this date range', false);
    }

    public function test_assigned_visit_needs_talk_notes_and_photo(): void
    {
        Storage::fake('public');
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $shop = Shop::query()->where('assigned_salesman_id', $salesman->id)->where('status', Shop::STATUS_ACTIVE)->firstOrFail();

        ShopVisit::query()
            ->where('salesman_id', $salesman->id)
            ->whereNull('checked_out_at')
            ->update(['checked_out_at' => now()]);

        $visit = ShopVisit::query()->create([
            'shop_id' => $shop->id,
            'salesman_id' => $salesman->id,
            'checked_in_at' => now(),
            'purpose' => 'Order collection',
            'outcome' => ShopVisit::OUTCOME_IN_PROGRESS,
        ]);

        $this->actingAs($salesman)
            ->get(route('field.visit.show', ['visit' => $visit, 'leave' => 1]))
            ->assertOk()
            ->assertSee('What did you talk about with the owner?')
            ->assertSee('Take a photo at this shop');

        $this->actingAs($salesman)
            ->post(route('field.visit.leave', $visit), [
                'outcome' => ShopVisit::OUTCOME_NO_ORDER,
                'redirect' => 'dashboard',
            ])
            ->assertSessionHasErrors(['notes', 'photo']);

        $this->actingAs($salesman)
            ->post(route('field.visit.leave', $visit), [
                'outcome' => ShopVisit::OUTCOME_NO_ORDER,
                'redirect' => 'dashboard',
                'notes' => 'Discussed last bill and next week stock.',
                'photo' => UploadedFile::fake()->image('evidence.jpg', 800, 600),
            ])
            ->assertRedirect(route('field.dashboard'));

        $visit->refresh();
        $this->assertFalse($visit->isOpen());
        $this->assertNotEmpty($visit->photo_path);
        Storage::disk('public')->assertExists($visit->photo_path);

        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $this->actingAs($admin)
            ->get(route('visits.index', ['period' => 'today']))
            ->assertOk()
            ->assertSee('Discussed last bill and next week stock.')
            ->assertSee($visit->photoUrl(), false);
    }
}
