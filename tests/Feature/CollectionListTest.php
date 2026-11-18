<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\SalesmanProfile;
use App\Models\Shop;
use App\Models\User;
use App\Support\DemoData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectionListTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_admin_salesman_and_owner_see_money_received_by_person(): void
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $shopUser = User::where('email', 'techzone@partner.local')->firstOrFail();
        $shop = Shop::query()->where('name', 'Tech Zone')->firstOrFail();

        Payment::query()->create([
            'number' => 'PAY-WHO-1500',
            'shop_id' => $shop->id,
            'amount' => 1500,
            'method' => Payment::METHOD_CASH,
            'status' => Payment::STATUS_VERIFIED,
            'paid_at' => now(),
            'verified_at' => now(),
            'created_by' => $salesman->id,
        ]);

        $amount = DemoData::taka(1500);

        $this->actingAs($admin)
            ->get(route('payments.index'))
            ->assertOk()
            ->assertSee('By person', false)
            ->assertSee($shop->owner_name)
            ->assertSee($shop->name);

        $this->actingAs($admin)
            ->get(route('payments.index', ['view' => 'receipts']))
            ->assertOk()
            ->assertSee('PAY-WHO-1500', false)
            ->assertSee($amount, false)
            ->assertSee($salesman->name);

        $this->actingAs($salesman)
            ->get(route('field.collections'))
            ->assertOk()
            ->assertSee('By person', false)
            ->assertSee($shop->owner_name)
            ->assertSee($amount, false)
            ->assertSee('You collected', false)
            ->assertSee('Collection rate', false);

        $this->actingAs($shopUser)
            ->get(route('portal.statement'))
            ->assertOk()
            ->assertSee('Money you paid', false)
            ->assertSee($amount, false)
            ->assertSee($salesman->name);
    }

    public function test_field_collections_filter_by_period_and_show_rate(): void
    {
        $salesman = User::query()->create([
            'name' => 'Collection Ranger',
            'email' => 'collections.ranger@field.local',
            'password' => 'Secret12345!',
            'portal' => User::PORTAL_SALESMAN,
            'is_active' => true,
        ]);
        $salesman->assignRole('Salesman');
        SalesmanProfile::query()->create([
            'user_id' => $salesman->id,
            'employee_code' => 'SM-COLLECT',
            'monthly_target' => 0,
            'is_active' => true,
            'joined_at' => now()->toDateString(),
        ]);

        $shop = Shop::query()->create([
            'code' => 'SHP-COLLECT-1',
            'name' => 'Range Filter Corner',
            'owner_name' => 'Owner Range',
            'phone' => '01800990011',
            'status' => Shop::STATUS_ACTIVE,
            'assigned_salesman_id' => $salesman->id,
            'created_by' => $salesman->id,
            'outstanding_balance' => 3500,
            'approved_at' => now(),
        ]);

        Payment::query()->create([
            'number' => 'PAY-TODAY-1000',
            'shop_id' => $shop->id,
            'amount' => 1000,
            'method' => Payment::METHOD_CASH,
            'status' => Payment::STATUS_VERIFIED,
            'paid_at' => now(),
            'verified_at' => now(),
            'created_by' => $salesman->id,
        ]);

        Payment::query()->create([
            'number' => 'PAY-OLD-500',
            'shop_id' => $shop->id,
            'amount' => 500,
            'method' => Payment::METHOD_CASH,
            'status' => Payment::STATUS_VERIFIED,
            'paid_at' => now()->subMonths(2),
            'verified_at' => now()->subMonths(2),
            'created_by' => $salesman->id,
        ]);

        $today = today()->toDateString();
        $lastMonthFrom = now()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $lastMonthTo = now()->subMonthNoOverflow()->endOfMonth()->toDateString();

        $this->actingAs($salesman)
            ->get(route('field.collections', ['from' => $today, 'to' => $today]))
            ->assertOk()
            ->assertSee('Owner Range', false)
            ->assertSee(DemoData::taka(1000), false)
            ->assertDontSee(DemoData::taka(500), false)
            ->assertSee('Still baki now', false)
            ->assertSee(DemoData::taka(3500), false)
            ->assertSee('Collection rate', false)
            // 1000 / (1000 + 3500) = 22.2%
            ->assertSee('22.2%', false)
            ->assertSee('100.0%', false); // office confirmed share of period collections

        $this->actingAs($salesman)
            ->get(route('field.collections', ['from' => $lastMonthFrom, 'to' => $lastMonthTo]))
            ->assertOk()
            ->assertSee('No collections in this range', false)
            ->assertSee(DemoData::taka(3500), false)
            ->assertSee('0.0%', false);
    }
}
