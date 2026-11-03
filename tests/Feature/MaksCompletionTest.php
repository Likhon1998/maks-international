<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\SalaryPayment;
use App\Models\Shop;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\SalaryService;
use App\Services\ShopLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaksCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $super;

    private User $salesman;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->super = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $this->salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $this->shop = User::where('email', 'techzone@partner.local')->firstOrFail()->primaryShop();
    }

    public function test_admin_can_save_shop_grade_and_rating(): void
    {
        $this->actingAs($this->super)->put(route('shops.update', $this->shop), [
            'name' => $this->shop->name,
            'owner_name' => $this->shop->owner_name,
            'phone' => $this->shop->phone,
            'email' => $this->shop->email,
            'address' => $this->shop->address,
            'city' => $this->shop->city,
            'territory_id' => $this->shop->territory_id,
            'price_group_id' => $this->shop->price_group_id,
            'assigned_salesman_id' => $this->shop->assigned_salesman_id,
            'credit_limit' => $this->shop->credit_limit,
            'payment_terms_days' => $this->shop->payment_terms_days,
            'status' => $this->shop->status,
            'notes' => $this->shop->notes,
            'grade' => 'A',
            'rating' => 4.5,
        ])->assertSessionHasNoErrors();

        $this->shop->refresh();
        $this->assertSame('A', $this->shop->grade);
        $this->assertEquals(4.5, (float) $this->shop->rating);
    }

    public function test_active_offer_sets_order_price(): void
    {
        $product = Product::query()
            ->where('status', Product::STATUS_ACTIVE)
            ->whereRaw('stock_on_hand - reserved_stock > 5')
            ->whereIn('id', WarehouseStock::query()->where('qty_on_hand', '>', 5)->select('product_id'))
            ->firstOrFail();

        ProductOffer::query()->create([
            'product_id' => $product->id,
            'title' => 'Flash deal',
            'offer_price' => 123.45,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
            'created_by' => $this->super->id,
        ]);

        $product->load('offers');
        $this->assertEquals(123.45, $product->priceForGroup($this->shop->priceGroup));
    }

    public function test_order_submit_rejects_oversell(): void
    {
        $product = Product::query()
            ->where('status', Product::STATUS_ACTIVE)
            ->where('is_published', true)
            ->firstOrFail();

        $this->actingAs($this->super)->post(route('orders.store'), [
            'shop_id' => $this->shop->id,
            'salesman_id' => $this->salesman->id,
            'items' => [['product_id' => $product->id, 'quantity' => 9_999_999]],
        ])->assertSessionHasErrors('stock');
    }

    public function test_due_management_and_statement_pages(): void
    {
        $this->shop->update(['outstanding_balance' => 5000]);

        $this->actingAs($this->super)
            ->get(route('dues.index'))
            ->assertOk()
            ->assertSee($this->shop->name);

        $this->actingAs($this->super)
            ->get(route('shops.statement', $this->shop))
            ->assertOk()
            ->assertSee('Account statement');

        $statement = app(ShopLedgerService::class)->statement($this->shop->fresh());
        $this->assertSame($this->shop->name, $statement['summary']['shop_name']);
    }

    public function test_salary_can_be_set_and_paid_once(): void
    {
        $service = app(SalaryService::class);
        $service->setMonthlySalary($this->salesman, 25000, now()->startOfMonth()->toDateString(), $this->super);
        $payment = $service->payMonth($this->salesman, now()->year, now()->month, 25000, 1000, 'Bank', null, $this->super);

        $this->assertSame(SalaryPayment::STATUS_PAID, $payment->status);
        $this->assertEquals(26000.0, $payment->total());

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->payMonth($this->salesman, now()->year, now()->month, 25000, 0, 'Bank', null, $this->super);
    }

    public function test_announcement_and_offers_admin_pages(): void
    {
        $this->actingAs($this->super)->post(route('announcements.store'), [
            'title' => 'Push collections',
            'body' => 'Focus on top dues this week.',
            'send_to_all' => 1,
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->salesman)
            ->get(route('field.announcements'))
            ->assertOk()
            ->assertSee('Push collections');

        $this->actingAs($this->salesman)
            ->get(route('field.notifications'))
            ->assertOk()
            ->assertSee('Push collections');

        $this->actingAs($this->super)
            ->get(route('offers.index'))
            ->assertOk();

        $this->actingAs($this->super)
            ->get(route('salaries.index'))
            ->assertOk();
    }
}
