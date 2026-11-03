<?php

namespace Tests\Feature;

use App\Models\Payment;
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
            ->assertSee('You collected', false);

        $this->actingAs($shopUser)
            ->get(route('portal.statement'))
            ->assertOk()
            ->assertSee('Money you paid', false)
            ->assertSee($amount, false)
            ->assertSee($salesman->name);
    }
}
