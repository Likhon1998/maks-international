<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\ShopVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldOrderSelectTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_salesman_can_select_a_shop_and_start_an_order(): void
    {
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
            ->get(route('field.orders.create'))
            ->assertOk()
            ->assertSee('Your shops and other shops', false)
            ->assertSee($shop->name)
            ->assertSee('Order on behalf of shop');

        $this->actingAs($salesman)
            ->post(route('field.shops.order', $shop))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $visit = ShopVisit::query()
            ->where('salesman_id', $salesman->id)
            ->where('shop_id', $shop->id)
            ->whereNull('checked_out_at')
            ->latest('id')
            ->first();

        $this->assertNotNull($visit);
        $this->assertSame('Order collection', $visit->purpose);

        $this->actingAs($salesman)
            ->get(route('field.visit.show', $visit))
            ->assertOk()
            ->assertSee($shop->name);
    }

    public function test_place_order_continues_open_visit_at_same_shop(): void
    {
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $shop = Shop::query()
            ->where('assigned_salesman_id', $salesman->id)
            ->where('status', Shop::STATUS_ACTIVE)
            ->firstOrFail();

        ShopVisit::query()
            ->where('salesman_id', $salesman->id)
            ->whereNull('checked_out_at')
            ->update(['checked_out_at' => now()]);

        $this->actingAs($salesman)->post(route('field.shops.order', $shop))->assertSessionHasNoErrors();
        $visit = ShopVisit::query()->where('salesman_id', $salesman->id)->whereNull('checked_out_at')->firstOrFail();

        $this->actingAs($salesman)
            ->post(route('field.shops.order', $shop))
            ->assertRedirect(route('field.visit.show', $visit));

        $this->assertSame(1, ShopVisit::query()->where('salesman_id', $salesman->id)->whereNull('checked_out_at')->count());
    }

    public function test_starting_order_at_another_shop_requires_leaving_first(): void
    {
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $first = Shop::query()
            ->where('assigned_salesman_id', $salesman->id)
            ->where('status', Shop::STATUS_ACTIVE)
            ->firstOrFail();
        $second = Shop::query()->create([
            'code' => 'SHP-ORDER2',
            'name' => 'Second Order Shop',
            'owner_name' => 'Owner Two',
            'phone' => '01870001122',
            'status' => Shop::STATUS_ACTIVE,
            'assigned_salesman_id' => $salesman->id,
            'created_by' => $salesman->id,
            'credit_limit' => 50000,
            'approved_at' => now(),
        ]);

        ShopVisit::query()
            ->where('salesman_id', $salesman->id)
            ->whereNull('checked_out_at')
            ->update(['checked_out_at' => now()]);

        $this->actingAs($salesman)->post(route('field.shops.order', $first))->assertSessionHasNoErrors();
        $firstVisit = ShopVisit::query()
            ->where('salesman_id', $salesman->id)
            ->where('shop_id', $first->id)
            ->whereNull('checked_out_at')
            ->firstOrFail();

        $this->actingAs($salesman)
            ->post(route('field.shops.order', $second))
            ->assertRedirect(route('field.visit.show', ['visit' => $firstVisit, 'leave' => 1]))
            ->assertSessionHas('error');

        $this->actingAs($salesman)
            ->post(route('field.shops.check-in', $second), ['purpose' => 'Shop visit'])
            ->assertRedirect(route('field.visit.show', ['visit' => $firstVisit, 'leave' => 1]))
            ->assertSessionHas('error');

        $this->assertNull($firstVisit->fresh()->checked_out_at);
        $this->assertFalse(
            ShopVisit::query()
                ->where('salesman_id', $salesman->id)
                ->where('shop_id', $second->id)
                ->whereNull('checked_out_at')
                ->exists()
        );
    }

    public function test_salesman_can_order_on_behalf_of_unassigned_admin_shop(): void
    {
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $shop = Shop::query()->create([
            'code' => 'SHP-ADMIN1',
            'name' => 'Admin Created Shop',
            'owner_name' => 'Office Owner',
            'phone' => '01870009988',
            'status' => Shop::STATUS_ACTIVE,
            'assigned_salesman_id' => null,
            'credit_limit' => 100000,
            'approved_at' => now(),
        ]);

        ShopVisit::query()
            ->where('salesman_id', $salesman->id)
            ->whereNull('checked_out_at')
            ->update(['checked_out_at' => now()]);

        $this->actingAs($salesman)
            ->get(route('field.orders.create'))
            ->assertOk()
            ->assertSee('Admin Created Shop');

        $this->actingAs($salesman)
            ->post(route('field.shops.order', $shop))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertNull($shop->fresh()->assigned_salesman_id);
        $this->assertTrue(
            ShopVisit::query()
                ->where('salesman_id', $salesman->id)
                ->where('shop_id', $shop->id)
                ->whereNull('checked_out_at')
                ->exists()
        );
    }

    public function test_any_salesman_can_order_on_behalf_of_another_salesmans_shop(): void
    {
        $karim = User::where('email', 'karim@field.local')->firstOrFail();
        $sabrina = User::where('email', 'sabrina@field.local')->firstOrFail();
        $shop = Shop::query()
            ->where('assigned_salesman_id', $karim->id)
            ->where('status', Shop::STATUS_ACTIVE)
            ->firstOrFail();

        ShopVisit::query()
            ->where('salesman_id', $sabrina->id)
            ->whereNull('checked_out_at')
            ->update(['checked_out_at' => now()]);

        $this->actingAs($sabrina)
            ->get(route('field.orders.create'))
            ->assertOk()
            ->assertSee($shop->name);

        $this->actingAs($sabrina)
            ->post(route('field.shops.order', $shop))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        // Shop stays on Karim's route; Sabrina still took the order visit.
        $this->assertSame($karim->id, $shop->fresh()->assigned_salesman_id);
        $this->assertTrue(
            ShopVisit::query()
                ->where('salesman_id', $sabrina->id)
                ->where('shop_id', $shop->id)
                ->whereNull('checked_out_at')
                ->exists()
        );
    }

    public function test_salesman_sees_assigned_and_other_shops_grouped_by_area(): void
    {
        $karim = User::where('email', 'karim@field.local')->firstOrFail();
        $mine = Shop::query()
            ->where('assigned_salesman_id', $karim->id)
            ->where('status', Shop::STATUS_ACTIVE)
            ->firstOrFail();
        $mine->update(['city' => 'Dhaka']);

        $other = Shop::query()->create([
            'code' => 'SHP-AREA2',
            'name' => 'Chattogram Corner',
            'owner_name' => 'Area Owner',
            'phone' => '01870002233',
            'city' => 'Chattogram',
            'status' => Shop::STATUS_ACTIVE,
            'assigned_salesman_id' => null,
            'credit_limit' => 50000,
            'approved_at' => now(),
        ]);

        $this->actingAs($karim)
            ->get(route('field.shops'))
            ->assertOk()
            ->assertSee('My shops', false)
            ->assertSee('Other shops', false)
            ->assertSee($mine->name)
            ->assertSee($other->name)
            ->assertSee('Dhaka', false)
            ->assertSee('Chattogram', false)
            ->assertSee('Unassigned', false);
    }

    public function test_admin_can_open_salesman_edit_and_assign_shops(): void
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $karim = User::where('email', 'karim@field.local')->firstOrFail();
        $shop = Shop::query()->where('status', Shop::STATUS_ACTIVE)->firstOrFail();

        $this->actingAs($admin)
            ->get(route('salesmen.edit', $karim))
            ->assertOk()
            ->assertSee('Assign dokan', false)
            ->assertSee('name="shop_ids[]"', false)
            ->assertSee($shop->name);

        $this->actingAs($admin)
            ->put(route('salesmen.update', $karim), [
                'name' => $karim->name,
                'email' => $karim->email,
                'phone' => $karim->phone,
                'employee_code' => $karim->salesmanProfile?->employee_code,
                'territory_id' => $karim->salesmanProfile?->territory_id,
                'monthly_target' => $karim->salesmanProfile?->monthly_target ?? 0,
                'is_active' => 1,
                'shop_ids' => [$shop->id],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('salesmen.show', $karim));

        $this->assertSame($karim->id, $shop->fresh()->assigned_salesman_id);
    }
}
