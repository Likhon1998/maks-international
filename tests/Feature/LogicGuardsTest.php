<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderFulfilment;
use App\Models\PartnerInquiry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\Purchase;
use App\Models\SalesTarget;
use App\Models\Shipment;
use App\Models\Shop;
use App\Models\ShopVisit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserAccessScope;
use App\Models\Warehouse;
use App\Services\CommissionService;
use App\Services\CreditService;
use App\Services\PaymentService;
use App\Services\ReturnService;
use App\Services\SalesmanService;
use App\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Regression tests for the money, stock and access rules found in the logic audit. */
class LogicGuardsTest extends TestCase
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
        $this->shop->update(['credit_limit' => 10_000_000]);
    }

    private function staff(string $role, string $email): User
    {
        $user = User::create([
            'name' => $role.' user', 'email' => $email, 'password' => 'Secret12345!',
            'portal' => User::PORTAL_ADMIN, 'is_active' => true,
        ]);
        $user->assignRole($role);
        UserAccessScope::create(['user_id' => $user->id, 'scope_type' => UserAccessScope::TYPE_GLOBAL]);

        return $user->fresh();
    }

    private function product(): Product
    {
        return Product::query()
            ->where('status', Product::STATUS_ACTIVE)
            ->whereRaw('stock_on_hand - reserved_stock > 10')
            ->whereIn('id', \App\Models\WarehouseStock::query()->where('qty_on_hand', '>', 10)->select('product_id'))
            ->orderBy('id')
            ->firstOrFail();
    }

    private function newOrder(int $qty = 2, ?Product $product = null): Order
    {
        $this->actingAs($this->super)->post(route('orders.store'), [
            'shop_id' => $this->shop->id,
            'salesman_id' => $this->salesman->id,
            'items' => [['product_id' => ($product ?? $this->product())->id, 'quantity' => $qty]],
        ])->assertSessionHasNoErrors();

        return Order::latest('id')->firstOrFail();
    }

    private function deliveredOrder(int $qty = 2, ?Product $product = null): Order
    {
        $order = $this->newOrder($qty, $product);
        $this->post(route('orders.approve', $order))->assertSessionHasNoErrors();
        $f = OrderFulfilment::where('order_id', $order->id)->firstOrFail();
        $this->post(route('fulfilment.start-pick', $f));
        $this->post(route('fulfilment.complete-pick', $f), ['warehouse_notes' => 'ok']);
        $this->post(route('fulfilment.pack', $f));
        $this->post(route('fulfilment.dispatch', $f), ['assigned_to' => $this->salesman->id, 'tracking_ref' => 'T']);
        $this->post(route('fulfilment.deliver', $f->fresh()))->assertSessionHasNoErrors();
        $this->assertSame(Order::STATUS_DELIVERED, $order->fresh()->status);

        return $order->fresh(['items']);
    }

    // C1 / C2 — user management cannot be used to escalate privileges.
    public function test_admin_cannot_escalate_to_super_admin_or_reset_super_admin(): void
    {
        $admin = $this->staff('Admin', 'admin2@bt.test');
        $base = [
            'name' => 'Sneaky', 'email' => 'sneaky@bt.test', 'password' => 'Secret12345!', 'password_confirmation' => 'Secret12345!',
            'portal' => 'admin', 'scope_type' => 'global', 'is_active' => 1,
        ];

        $this->actingAs($admin)->post(route('users.store'), $base + ['roles' => ['Super Admin']])->assertForbidden();
        $this->post(route('users.store'), $base + ['permissions' => ['permissions.manage']])->assertForbidden();
        $this->assertNull(User::where('email', 'sneaky@bt.test')->first());

        $this->post(route('users.store'), $base + ['roles' => ['Salesman']])->assertSessionHasNoErrors();
        $this->assertTrue(User::where('email', 'sneaky@bt.test')->first()->hasRole('Salesman'));

        $this->put(route('users.update', $admin), [
            'name' => $admin->name, 'email' => $admin->email, 'portal' => 'admin', 'scope_type' => 'global',
            'is_active' => 1, 'roles' => ['Admin', 'Super Admin'],
        ])->assertForbidden();
        $this->assertFalse($admin->fresh()->isSuperAdmin());

        $this->post(route('users.reset-password', $this->super), ['password' => 'Hacked123!', 'password_confirmation' => 'Hacked123!'])
            ->assertForbidden();
        $this->assertFalse(Hash::check('Hacked123!', $this->super->fresh()->password));
    }

    // C3 — issuing a shop login can never take over a staff/salesman account or another shop's login.
    public function test_shop_credentials_cannot_take_over_other_accounts(): void
    {
        $other = Shop::where('id', '!=', $this->shop->id)->where('status', Shop::STATUS_ACTIVE)->firstOrFail();
        $salesmanHash = $this->salesman->password;

        $this->actingAs($this->super)->post(route('shops.credentials', $other), [
            'login_email' => $this->salesman->email, 'login_password' => 'Owned1234',
        ])->assertSessionHasErrors('login_email');
        $this->assertSame($salesmanHash, $this->salesman->fresh()->password);
        $this->assertSame(User::PORTAL_SALESMAN, $this->salesman->fresh()->portal);

        $this->post(route('shops.credentials', $other), [
            'login_email' => 'techzone@partner.local', 'login_password' => 'Owned1234',
        ])->assertSessionHasErrors('login_email');
        $this->assertFalse(Hash::check('Owned1234', User::where('email', 'techzone@partner.local')->first()->password));
    }

    // C4 — a payment verified twice (double click / two tabs) is applied once.
    public function test_payment_verified_twice_is_applied_once(): void
    {
        $order = $this->deliveredOrder();
        $invoice = Invoice::findOrFail($order->invoice_id);
        $payments = app(PaymentService::class);

        $payment = $payments->record(['shop_id' => $this->shop->id, 'invoice_id' => $invoice->id, 'amount' => 100, 'method' => 'cash'], $this->super);
        $stale = Payment::findOrFail($payment->id);
        $payments->verify($payment, $this->super);
        $payments->verify($stale, $this->super);

        $this->assertEqualsWithDelta(100, (float) $invoice->fresh()->paid_amount, 0.01);
        $this->assertSame(1, Commission::where('payment_id', $payment->id)->count());

        $this->expectException(ValidationException::class);
        $payments->reject($stale, 'too late', $this->super);
    }

    // C5 — a shipment received twice only adds stock once.
    public function test_shipment_received_twice_adds_stock_once(): void
    {
        $this->actingAs($this->super);
        $product = Product::where('status', Product::STATUS_ACTIVE)->firstOrFail();
        $warehouse = Warehouse::firstOrFail();
        $this->post(route('purchases.store'), [
            'supplier_id' => Supplier::firstOrFail()->id, 'currency' => 'USD', 'exchange_rate' => 120,
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost_foreign' => 5]],
        ]);
        $this->post(route('purchases.shipment', Purchase::latest('id')->firstOrFail()), ['freight_cost' => 100, 'warehouse_id' => $warehouse->id]);
        $shipment = Shipment::latest('id')->firstOrFail();
        $this->post(route('shipments.arrive', $shipment));

        $stale = Shipment::findOrFail($shipment->id);
        $before = (int) $product->fresh()->stock_on_hand;
        app(ShipmentService::class)->receive($shipment->fresh(), $this->super, $warehouse->id);

        try {
            app(ShipmentService::class)->receive($stale, $this->super, $warehouse->id);
            $this->fail('Second receive should be refused.');
        } catch (ValidationException) {
        }
        $this->assertSame($before + 10, (int) $product->fresh()->stock_on_hand);
    }

    // C6 / H2 — returns need a delivered order, are capped at what was sold, use the order price, and approve once.
    public function test_returns_are_tied_to_delivered_order_quantities_and_prices(): void
    {
        $product = $this->product();
        $order = $this->deliveredOrder(2, $product);
        $price = (float) $order->items->first()->unit_price;

        $this->post(route('returns.store'), [
            'shop_id' => $this->shop->id, 'reason_type' => 'defect',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('order_id');

        $other = Shop::where('id', '!=', $this->shop->id)->firstOrFail();
        $this->post(route('returns.store'), [
            'shop_id' => $other->id, 'order_id' => $order->id, 'reason_type' => 'defect',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('order_id');

        $this->post(route('returns.store'), [
            'shop_id' => $this->shop->id, 'order_id' => $order->id, 'reason_type' => 'defect',
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ])->assertSessionHasErrors('items');

        $this->post(route('returns.store'), [
            'shop_id' => $this->shop->id, 'order_id' => $order->id, 'reason_type' => 'defect',
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 999999]],
        ])->assertSessionHasNoErrors();
        $return = ProductReturn::latest('id')->firstOrFail();
        $this->assertEqualsWithDelta($price * 2, (float) $return->total, 0.01);

        $this->post(route('returns.store'), [
            'shop_id' => $this->shop->id, 'order_id' => $order->id, 'reason_type' => 'defect',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('items');

        $stale = ProductReturn::findOrFail($return->id);
        $stockBefore = (int) $product->fresh()->stock_on_hand;
        // Approve + restock in one step — inventory must not change on approve alone.
        app(ReturnService::class)->approve($return, $this->super, null, restockNow: true);
        try {
            app(ReturnService::class)->approve($stale, $this->super, null, restockNow: true);
            $this->fail('Second approve should be refused.');
        } catch (ValidationException) {
        }
        $this->assertSame($stockBefore + 2, (int) $product->fresh()->stock_on_hand);
    }

    // H1 / H3 — advance collected without verify rights stays pending; an order with money on it can't be deleted.
    public function test_advance_needs_verifier_and_paid_orders_cannot_be_deleted(): void
    {
        $manager = $this->staff('Sales Manager', 'manager@bt.test');
        $order = $this->newOrder(1);
        $this->post(route('orders.request-advance', $order), ['advance_amount' => 100])->assertSessionHasNoErrors();

        $this->actingAs($manager)->post(route('orders.collect-advance', $order), ['method' => 'cash'])->assertSessionHasNoErrors();
        $payment = Payment::where('invoice_id', $order->fresh()->advance_invoice_id)->firstOrFail();
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
        $this->assertSame(Order::STATUS_AWAITING_ADVANCE, $order->fresh()->status);

        $this->actingAs($this->super)->delete(route('orders.destroy', $order));
        $this->assertNotNull(Order::find($order->id), 'An order with a recorded payment must not be deletable.');
    }

    // H3 / H4 — cancelling an order with a paid advance voids its invoice and turns the money into shop credit.
    public function test_cancelled_order_advance_becomes_shop_credit(): void
    {
        $order = $this->newOrder(1);
        $this->post(route('orders.request-advance', $order), ['advance_amount' => 100]);
        $this->post(route('orders.collect-advance', $order), ['method' => 'cash'])->assertSessionHasNoErrors();
        $advance = Invoice::findOrFail($order->fresh()->advance_invoice_id);
        $payment = Payment::where('invoice_id', $advance->id)->firstOrFail();
        $this->assertSame(Payment::STATUS_VERIFIED, $payment->status);
        $commission = Commission::where('payment_id', $payment->id)->first();

        $this->post(route('orders.approve', $order))->assertSessionHasNoErrors();
        $this->post(route('orders.cancel', $order), ['cancellation_reason' => 'changed mind'])->assertSessionHasNoErrors();

        $this->assertSame(Invoice::STATUS_VOID, $advance->fresh()->status);
        $this->assertNull($payment->fresh()->invoice_id);
        $this->assertEqualsWithDelta(100, app(CreditService::class)->unappliedCredit($this->shop), 0.01);
        if ($commission) {
            $this->assertSame(Commission::STATUS_REJECTED, $commission->fresh()->status);
        }

        $owedBefore = (float) app(CreditService::class)->recalculateOutstanding($this->shop)->outstanding_balance;
        $this->post(route('payments.store'), ['shop_id' => $this->shop->id, 'amount' => 50, 'method' => 'cash']);
        $this->post(route('payments.verify', Payment::latest('id')->first()))->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(max(0, $owedBefore - 50), (float) $this->shop->fresh()->outstanding_balance, 0.01);
    }

    // Credit limit is informational only — shops may buy freely even when over the stored limit.
    public function test_credit_limit_does_not_block_approval(): void
    {
        $order = $this->newOrder(2);
        $this->shop->update(['credit_limit' => 1]);

        $this->post(route('orders.approve', $order))->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame(Order::STATUS_APPROVED, $order->status);
        $this->assertFalse((bool) $order->credit_override);
    }

    // H5 — saving a salesman never silently drops shops the form didn't show.
    public function test_salesman_shop_sync_keeps_shops_the_form_does_not_list(): void
    {
        $copy = fn (string $name, string $status) => tap($this->shop->replicate()->fill([
            'name' => $name, 'code' => 'T-'.strtoupper(substr(md5($name), 0, 6)), 'email' => null,
            'status' => $status, 'assigned_salesman_id' => $this->salesman->id,
        ]))->save();
        $pending = $copy('Pending Corner Shop', Shop::STATUS_PENDING);
        $rejected = $copy('Rejected Corner Shop', Shop::STATUS_REJECTED);

        $this->actingAs($this->super)->get(route('salesmen.edit', $this->salesman))->assertOk()->assertSee($pending->name);

        app(SalesmanService::class)->syncAssignedShops($this->salesman, [$pending->id]);
        $this->assertSame($this->salesman->id, $pending->fresh()->assigned_salesman_id);
        $this->assertSame($this->salesman->id, $rejected->fresh()->assigned_salesman_id);
    }

    // H6 — a rejected target bonus is not re-created on the next payment.
    public function test_rejected_target_bonus_is_not_reissued(): void
    {
        $target = SalesTarget::firstOrFail();
        Commission::where('sales_target_id', $target->id)->where('type', Commission::TYPE_TARGET_BONUS)->forceDelete();
        $target->forceFill(['target_met' => true, 'collected_amount' => 100000])->save();
        $commissions = app(CommissionService::class);

        $bonus = $commissions->maybeAccrueTargetBonus($target, $this->super);
        $this->assertNotNull($bonus);
        $commissions->reject($bonus, 'not earned', $this->super);

        $again = $commissions->maybeAccrueTargetBonus($target->fresh(), $this->super);
        $this->assertSame($bonus->id, $again->id);
        $this->assertSame(1, Commission::where('sales_target_id', $target->id)->where('type', Commission::TYPE_TARGET_BONUS)->count());
    }

    // H7 — a salesman can only set a partner's first login, for a shop still assigned to them.
    public function test_salesman_cannot_reset_partner_login_later_or_for_reassigned_shop(): void
    {
        $this->actingAs($this->salesman)->post(route('field.shops.store'), [
            'name' => 'Login Guard Store', 'owner_name' => 'Rafiq', 'phone' => '01712349999', 'city' => 'Mirpur',
        ]);
        $shop = Shop::where('name', 'Login Guard Store')->firstOrFail();
        $this->post(route('field.partners.store'), [
            'shop_id' => $shop->id, 'business_name' => 'Login Guard Store', 'email' => 'rafiq@example.com',
            'phone' => '01712349999', 'city' => 'Mirpur', 'business_type' => 'retailer',
        ])->assertSessionHasNoErrors();
        $inquiry = PartnerInquiry::where('submitted_by', $this->salesman->id)->latest('id')->firstOrFail();
        $this->actingAs($this->super)->post(route('partner-inquiries.accept', $inquiry));
        $this->assertTrue($inquiry->fresh()->needsLogin());

        $other = User::where('portal', User::PORTAL_SALESMAN)->where('id', '!=', $this->salesman->id)->firstOrFail();
        $shop->refresh()->update(['assigned_salesman_id' => $other->id]);
        $this->actingAs($this->salesman)->post(route('field.partners.login.store', $inquiry), ['email' => 'rafiq@example.com', 'password' => 'Rafiq2026!'])
            ->assertForbidden();

        $shop->update(['assigned_salesman_id' => $this->salesman->id]);
        $this->post(route('field.partners.login.store', $inquiry), ['email' => 'rafiq@example.com', 'password' => 'Rafiq2026!'])
            ->assertSessionHasNoErrors();
        $this->assertSame('rafiq@example.com', $inquiry->fresh()->portal_email);

        $this->flushSession();
        $this->actingAs($this->salesman)->get(route('field.partners.login', $inquiry))
            ->assertRedirect(route('field.partners'))->assertSessionHas('error');
        $this->post(route('field.partners.login.store', $inquiry), ['email' => 'rafiq@example.com', 'password' => 'Taken2026!'])
            ->assertForbidden();
        $this->assertTrue(Hash::check('Rafiq2026!', User::where('email', 'rafiq@example.com')->first()->password));
    }

    private function userWith(array $permissions, string $email, ?array $scope = null): User
    {
        $user = User::create([
            'name' => 'Limited user', 'email' => $email, 'password' => 'Secret12345!',
            'portal' => User::PORTAL_ADMIN, 'is_active' => true,
        ]);
        $user->givePermissionTo($permissions);
        UserAccessScope::create(['user_id' => $user->id] + ($scope ?? ['scope_type' => UserAccessScope::TYPE_GLOBAL]));

        return $user->fresh();
    }

    // M2 — picking can never consume more than is reserved (other orders' reservations stay intact).
    public function test_pick_cannot_take_more_than_reserved(): void
    {
        $product = $this->product();
        $warehouse = Warehouse::defaultWarehouse();
        $order = $this->newOrder(1, $product);
        $reserved = (int) app(\App\Services\InventoryService::class)->stockFor($warehouse, $product)->qty_reserved;

        $this->expectException(ValidationException::class);
        app(\App\Services\InventoryService::class)->pick($warehouse, $product, $reserved + 1, $order, $this->super);
    }

    // M3 — a purchase can't be put on more shipments than was ordered.
    public function test_purchase_cannot_be_shipped_twice(): void
    {
        $this->actingAs($this->super);
        $this->post(route('purchases.store'), [
            'supplier_id' => Supplier::firstOrFail()->id, 'currency' => 'USD', 'exchange_rate' => 120,
            'items' => [['product_id' => Product::firstOrFail()->id, 'quantity' => 10, 'unit_cost_foreign' => 5]],
        ]);
        $purchase = Purchase::latest('id')->firstOrFail();
        $warehouse = Warehouse::firstOrFail();

        $this->post(route('purchases.shipment', $purchase), ['freight_cost' => 100, 'warehouse_id' => $warehouse->id])->assertSessionHasNoErrors();
        $count = Shipment::count();
        $this->post(route('purchases.shipment', $purchase), ['freight_cost' => 100, 'warehouse_id' => $warehouse->id]);
        $this->assertSame($count, Shipment::count());
    }

    // M4 — an approved return reduces the invoice balance and the shop's outstanding exactly once.
    public function test_return_credit_is_applied_to_the_invoice(): void
    {
        $product = $this->product();
        $order = $this->deliveredOrder(2, $product);
        $invoice = Invoice::findOrFail($order->invoice_id);
        $owedBefore = (float) app(CreditService::class)->recalculateOutstanding($this->shop)->outstanding_balance;

        $this->post(route('returns.store'), [
            'shop_id' => $this->shop->id, 'order_id' => $order->id, 'reason_type' => 'defect',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();
        $return = ProductReturn::latest('id')->firstOrFail();
        app(ReturnService::class)->approve($return, $this->super);

        $invoice->refresh();
        $this->assertEqualsWithDelta((float) $return->total, (float) $invoice->credited_amount, 0.01);
        $balance = (float) $invoice->total - (float) $invoice->paid_amount - (float) $invoice->credited_amount;
        $this->assertEqualsWithDelta((float) $invoice->total - (float) $invoice->paid_amount - (float) $return->total, $balance, 0.01);
        $this->assertSame(Invoice::STATUS_PARTIAL, $invoice->status);
        $this->assertEqualsWithDelta($owedBefore - (float) $return->total, (float) $this->shop->fresh()->outstanding_balance, 0.01);
    }

    // M6 / M7 / M18 — shop status, credit, prices and stock need their own permissions; phones stay unique.
    public function test_sensitive_fields_need_their_own_permission(): void
    {
        $editor = $this->userWith(['shops.view', 'shops.edit', 'products.view', 'products.edit'], 'editor@bt.test');
        $shop = $this->shop->fresh();
        $base = ['name' => $shop->name, 'owner_name' => $shop->owner_name ?: 'Owner', 'phone' => $shop->phone, 'status' => $shop->status];

        $this->actingAs($editor)->put(route('shops.update', $shop), ['status' => Shop::STATUS_ON_HOLD] + $base)->assertSessionHasErrors('status');
        $this->put(route('shops.update', $shop), $base + ['credit_limit' => 1])->assertSessionHasErrors('credit_limit');
        $this->assertSame(Shop::STATUS_ACTIVE, $shop->fresh()->status);

        $product = $this->product();
        $productBase = ['name' => $product->name, 'sku' => $product->sku, 'wholesale_price' => $product->wholesale_price, 'status' => $product->status];
        $this->put(route('products.update', $product), ['wholesale_price' => (float) $product->wholesale_price + 50] + $productBase)->assertSessionHasErrors('wholesale_price');
        $this->put(route('products.update', $product), $productBase + ['stock_on_hand' => (int) $product->stock_on_hand + 99])->assertSessionHasErrors('stock_on_hand');
        $this->assertEqualsWithDelta((float) $product->wholesale_price, (float) $product->fresh()->wholesale_price, 0.01);

        $other = Shop::where('id', '!=', $shop->id)->whereNotNull('phone')->firstOrFail();
        $this->actingAs($this->super)->post(route('shops.store'), [
            'name' => 'Duplicate Phone Shop', 'owner_name' => 'Dup', 'phone' => $other->phone, 'status' => Shop::STATUS_ACTIVE,
        ])->assertSessionHasErrors('phone');
    }

    // M12 — a user scoped to one shop only sees and acts on that shop's records.
    public function test_shop_scoped_user_only_sees_their_shop(): void
    {
        $user = $this->userWith(['orders.view', 'invoices.view', 'payments.view', 'payments.verify'], 'scoped@bt.test',
            ['scope_type' => UserAccessScope::TYPE_SHOP, 'scope_id' => $this->shop->id]);
        $mine = $this->deliveredOrder(1);
        $otherOrder = Order::where('shop_id', '!=', $this->shop->id)->firstOrFail();
        $otherInvoice = tap(Invoice::findOrFail($mine->invoice_id)->replicate()->fill([
            'shop_id' => $otherOrder->shop_id, 'order_id' => $otherOrder->id, 'number' => 'INV-SCOPE-TEST',
        ]))->save();

        $this->actingAs($user)->get(route('orders.index'))->assertOk()->assertSee($mine->number)->assertDontSee($otherOrder->number);
        $this->get(route('invoices.show', $otherInvoice))->assertForbidden();
        $this->get(route('invoices.show', $mine->invoice_id))->assertOk();
        $this->get(route('orders.show', $otherOrder))->assertForbidden();
    }

    // L9 — notification links never send the user to another site.
    public function test_notification_links_stay_on_site(): void
    {
        foreach (['https://evil.example/admin', '//evil.example/admin', 'javascript:alert(1)'] as $url) {
            $note = $this->super->notifications()->create([
                'id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'test', 'data' => ['title' => 'x', 'url' => $url],
            ]);
            $location = $this->actingAs($this->super)->post(route('notifications.read', $note->id))->assertRedirect()->headers->get('Location');
            $this->assertStringStartsWith(url('/admin'), $location, "{$url} must not redirect off-site");
        }

        $note = $this->super->notifications()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'test', 'data' => ['title' => 'x', 'url' => url('/admin/orders?status=pending')],
        ]);
        $this->post(route('notifications.read', $note->id))->assertRedirect('/admin/orders?status=pending');
    }

    // L7 — every portal can change its own password, only with the current one.
    public function test_users_can_change_their_own_password(): void
    {
        $this->actingAs($this->salesman)->get(route('field.account.password'))->assertOk();
        $this->put(route('field.account.password.update'), [
            'current_password' => 'wrong-one', 'password' => 'NewPass2026!', 'password_confirmation' => 'NewPass2026!',
        ])->assertSessionHasErrors('current_password');
        $this->put(route('field.account.password.update'), [
            'current_password' => '12345678', 'password' => 'NewPass2026!', 'password_confirmation' => 'NewPass2026!',
        ])->assertSessionHasNoErrors()->assertRedirect(route('field.account.password'));
        $this->assertTrue(Hash::check('NewPass2026!', $this->salesman->fresh()->password));

        $shopUser = User::where('email', 'techzone@partner.local')->firstOrFail();
        $this->actingAs($shopUser)->get(route('portal.account.password'))->assertOk();
        $this->actingAs($this->super)->get(route('account.password'))->assertOk();
    }

    // M13 — one visit can only ever produce one order, even on a double tap.
    public function test_visit_cannot_send_two_orders(): void
    {
        $shop = Shop::where('assigned_salesman_id', $this->salesman->id)->where('status', Shop::STATUS_ACTIVE)->firstOrFail();
        ShopVisit::where('salesman_id', $this->salesman->id)->whereNull('checked_out_at')->update(['checked_out_at' => now()]);

        $this->actingAs($this->salesman)->post(route('field.shops.check-in', $shop))->assertSessionHasNoErrors();
        $visit = ShopVisit::where('salesman_id', $this->salesman->id)->latest('id')->firstOrFail();
        $items = [
            'items' => [['product_id' => $this->product()->id, 'quantity' => 1]],
            'notes' => 'Talked about restock.',
        ];
        $stale = ShopVisit::findOrFail($visit->id);

        $this->post(route('field.visit.order', $visit), $items)->assertSessionHasNoErrors();
        $this->post(route('field.visit.order', $stale), $items)->assertSessionHasErrors();
        $this->assertSame(1, Order::where('visit_id', $visit->id)->count());

        $this->post(route('field.shops.check-in', $shop))->assertSessionHasNoErrors();
        $open = ShopVisit::where('salesman_id', $this->salesman->id)->whereNull('checked_out_at')->firstOrFail();
        $this->post(route('field.shops.check-in', $shop))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('field.visit.show', $open));
        $this->assertSame(1, ShopVisit::where('salesman_id', $this->salesman->id)->whereNull('checked_out_at')->count());
    }

    // M14 / M15 — returns count against the target; dropping under it withdraws the unpaid reward and bonus.
    public function test_returns_reduce_target_and_withdraw_unpaid_bonus(): void
    {
        $product = $this->product();
        $order = $this->deliveredOrder(2, $product);
        $targets = app(\App\Services\TargetService::class);
        $target = $targets->ensureForSalesman($this->salesman, now()->year, now()->month);
        \App\Models\Reward::where('sales_target_id', $target->id)->forceDelete();
        Commission::where('sales_target_id', $target->id)->where('type', Commission::TYPE_TARGET_BONUS)->forceDelete();

        $achieved = $targets->achievedAmount($this->salesman->id, now()->startOfMonth(), now()->endOfMonth());
        $target->update(['target_amount' => $achieved]);
        $target = $targets->recalculate($target);
        $this->assertTrue((bool) $target->target_met);
        $reward = \App\Models\Reward::where('sales_target_id', $target->id)->firstOrFail();
        $bonus = Commission::where('sales_target_id', $target->id)->where('type', Commission::TYPE_TARGET_BONUS)->firstOrFail();

        $this->post(route('returns.store'), [
            'shop_id' => $this->shop->id, 'order_id' => $order->id, 'reason_type' => 'defect',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();
        app(ReturnService::class)->approve(ProductReturn::latest('id')->firstOrFail(), $this->super);

        $target->refresh();
        $this->assertFalse((bool) $target->target_met);
        $this->assertEqualsWithDelta($achieved - (float) ProductReturn::latest('id')->first()->total, (float) $target->achieved_amount, 0.01);
        $this->assertSame(\App\Models\Reward::STATUS_CANCELLED, $reward->fresh()->status);
        $this->assertSame(Commission::STATUS_REJECTED, $bonus->fresh()->status);

        $rewards = app(\App\Services\RewardService::class);
        $manual = $rewards->createManual([
            'salesman_id' => $this->salesman->id, 'title' => 'Manual', 'year' => now()->year, 'month' => now()->month, 'amount' => 100,
        ], $this->super);
        $this->expectException(ValidationException::class);
        $rewards->markPaid($manual, $this->super);
    }

    // M16 — an accepted partner request can't be rejected or reopened.
    public function test_accepted_partner_request_is_final(): void
    {
        $inquiry = PartnerInquiry::create([
            'business_name' => 'Final Answer Store', 'contact_name' => 'Owner', 'email' => 'final@example.com',
            'phone' => '01799887766', 'city' => 'Dhaka', 'status' => PartnerInquiry::STATUS_NEW,
        ]);
        $this->actingAs($this->super)->post(route('partner-inquiries.accept', $inquiry));
        $this->assertSame(PartnerInquiry::STATUS_CONVERTED, $inquiry->fresh()->status);

        $this->post(route('partner-inquiries.reject', $inquiry))->assertSessionHas('error');
        $this->post(route('partner-inquiries.pending', $inquiry))->assertSessionHas('error');
        $this->assertSame(PartnerInquiry::STATUS_CONVERTED, $inquiry->fresh()->status);

        $dupe = PartnerInquiry::create([
            'business_name' => 'Same Phone Store', 'contact_name' => 'Other', 'email' => 'samephone@example.com',
            'phone' => '01799887766', 'city' => 'Dhaka', 'status' => PartnerInquiry::STATUS_NEW,
        ]);
        $this->post(route('partner-inquiries.accept', $dupe))->assertSessionHas('error');
        $this->assertSame(1, Shop::where('phone', '01799887766')->count());
    }

    // L1 — document numbers skip values that are already taken.
    public function test_document_numbers_never_collide(): void
    {
        $orders = app(\App\Services\OrderService::class);
        $next = $orders->nextNumber();
        $this->newOrder(1)->forceFill(['number' => $next])->save();
        $this->assertNotSame($next, $orders->nextNumber());
        $this->assertFalse(Order::withTrashed()->where('number', $orders->nextNumber())->exists());
    }
}
