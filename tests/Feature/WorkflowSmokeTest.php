<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\ContactMessage;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderFulfilment;
use App\Models\PartnerInquiry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\Purchase;
use App\Models\Reward;
use App\Models\SalesTarget;
use App\Models\Shipment;
use App\Models\Shop;
use App\Models\ShopVisit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkflowSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $admin;

    private User $salesman;

    private User $shopUser;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $this->salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $this->shopUser = User::where('email', 'techzone@partner.local')->firstOrFail();
        $this->shop = $this->shopUser->primaryShop();
    }

    private function ok(TestResponse $response, string $step): TestResponse
    {
        $errors = session('errors')?->getBag('default')->all() ?? [];
        $flashError = session('error');

        $this->assertLessThan(
            400,
            $response->status(),
            "[{$step}] HTTP {$response->status()}: ".mb_substr((string) ($response->exception?->getMessage() ?? strip_tags($response->getContent())), 0, 600)
        );
        $this->assertSame([], $errors, "[{$step}] validation errors: ".implode(' | ', $errors));
        $this->assertNull($flashError, "[{$step}] flash error: {$flashError}");

        return $response;
    }

    protected function tearDown(): void
    {
        if ($this->app && $this->status()->isSuccess()) {
            $this->assertBooksBalance();
        }

        parent::tearDown();
    }

    private function assertBooksBalance(): void
    {
        $eq = fn ($a, $b) => abs((float) $a - (float) $b) < 0.01;
        $issues = [];

        foreach (DB::table('invoices')->where('status', '!=', 'void')->get() as $inv) {
            $verified = (float) DB::table('payments')->where('invoice_id', $inv->id)->where('status', 'verified')->sum('amount');
            if (! $eq($inv->balance, max(0, $inv->total - $inv->paid_amount - $inv->credited_amount))) {
                $issues[] = "invoice {$inv->number} balance {$inv->balance} vs total-paid";
            }
            if (! $eq($inv->paid_amount, min($verified, $inv->total))) {
                $issues[] = "invoice {$inv->number} paid {$inv->paid_amount} vs verified payments {$verified}";
            }
        }
        foreach (Shop::all() as $shop) {
            $open = (float) DB::table('invoices')->where('shop_id', $shop->id)->whereIn('status', ['issued', 'partial'])->sum('balance');
            $credits = (float) DB::table('returns')->where('shop_id', $shop->id)->where('status', 'approved')->where('credit_issued', true)->sum(DB::raw('total - applied_to_invoice'))
                + (float) DB::table('payments')->where('shop_id', $shop->id)->where('status', 'verified')->whereNull('invoice_id')->sum('amount');
            if (! $eq($shop->outstanding_balance, max(0, round($open - $credits, 2)))) {
                $issues[] = "shop {$shop->code} outstanding {$shop->outstanding_balance} vs ".max(0, round($open - $credits, 2));
            }
        }
        foreach (Product::all() as $p) {
            $onHand = (int) DB::table('warehouse_stocks')->where('product_id', $p->id)->sum('qty_on_hand');
            $reserved = (int) DB::table('warehouse_stocks')->where('product_id', $p->id)->sum('qty_reserved');
            if ((int) $p->stock_on_hand !== $onHand || (int) $p->reserved_stock !== $reserved) {
                $issues[] = "product {$p->sku} stock {$p->stock_on_hand}/{$p->reserved_stock} vs warehouses {$onHand}/{$reserved}";
            }
        }
        foreach (DB::table('warehouse_stocks')->get() as $ws) {
            if ($ws->qty_on_hand < 0 || $ws->qty_reserved < 0 || $ws->qty_reserved > $ws->qty_on_hand) {
                $issues[] = "warehouse stock #{$ws->id} on hand {$ws->qty_on_hand} reserved {$ws->qty_reserved}";
            }
        }

        $this->assertSame([], $issues, "Books out of balance:\n".implode("\n", $issues));
    }

    private function stockedProduct(): Product
    {
        return Product::query()
            ->where('status', Product::STATUS_ACTIVE)
            ->whereRaw('stock_on_hand - reserved_stock > 10')
            ->whereIn('id', \App\Models\WarehouseStock::query()->where('qty_on_hand', '>', 10)->select('product_id'))
            ->orderBy('id')
            ->firstOrFail();
    }

    public function test_admin_order_to_commission_lifecycle(): void
    {
        $this->actingAs($this->admin);
        $product = $this->stockedProduct();

        $this->ok($this->post(route('orders.store'), [
            'shop_id' => $this->shop->id,
            'salesman_id' => $this->salesman->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ]), 'create order');
        $order = Order::latest('id')->firstOrFail();
        $this->assertSame(Order::STATUS_PENDING_AUDIT, $order->status);

        $this->ok($this->post(route('orders.approve', $order), ['credit_override' => 1]), 'approve order');
        $order->refresh();
        $this->assertSame(Order::STATUS_APPROVED, $order->status);

        $fulfilment = OrderFulfilment::where('order_id', $order->id)->firstOrFail();
        $this->ok($this->get(route('fulfilment.workspace', $fulfilment)), 'workspace');
        $this->ok($this->post(route('fulfilment.start-pick', $fulfilment)), 'start pick');
        $this->ok($this->post(route('fulfilment.complete-pick', $fulfilment), ['warehouse_notes' => 'ok']), 'complete pick');
        $this->ok($this->post(route('fulfilment.pack', $fulfilment)), 'pack');
        $this->ok($this->post(route('fulfilment.dispatch', $fulfilment), ['assigned_to' => $this->admin->id, 'tracking_ref' => 'TRK1']), 'dispatch');
        $this->ok($this->post(route('fulfilment.deliver', $fulfilment->fresh())), 'deliver');
        $this->assertSame(Order::STATUS_DELIVERED, $order->fresh()->status);

        $invoice = Invoice::where('order_id', $order->id)->first();
        if (! $invoice) {
            $this->ok($this->post(route('orders.invoice', $order)), 'issue invoice');
            $invoice = Invoice::where('order_id', $order->id)->firstOrFail();
        }
        $this->ok($this->get(route('invoices.show', $invoice)), 'invoice page');
        $this->ok($this->get(route('invoices.download', $invoice)), 'invoice pdf');

        $this->ok($this->post(route('payments.store'), [
            'shop_id' => $this->shop->id,
            'invoice_id' => $invoice->id,
            'amount' => (float) $invoice->fresh()->balance,
            'method' => 'cash',
        ]), 'record payment');
        $payment = Payment::latest('id')->firstOrFail();
        $this->ok($this->post(route('payments.verify', $payment)), 'verify payment');
        $this->assertSame(Payment::STATUS_VERIFIED, $payment->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        $commission = Commission::where('payment_id', $payment->id)->firstOrFail();
        $this->ok($this->post(route('commissions.approve', $commission)), 'approve commission');
        $this->ok($this->post(route('commissions.pay', $commission)), 'pay commission');
        $this->assertSame(Commission::STATUS_PAID, $commission->fresh()->status);

        $this->ok($this->post(route('returns.store'), [
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'reason_type' => 'defect',
            'restock' => 1,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ]), 'create return');
        $return = ProductReturn::latest('id')->firstOrFail();
        $this->ok($this->post(route('returns.approve', $return), ['resolution_notes' => 'fine']), 'approve return');

        $this->ok($this->post(route('returns.store'), [
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'reason_type' => 'other',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]), 'create return 2');
        $this->ok($this->post(route('returns.reject', ProductReturn::latest('id')->first()), ['resolution_notes' => 'no']), 'reject return');

        foreach (['dashboard', 'orders.index', 'invoices.index', 'payments.index', 'commissions.index', 'returns.index', 'deliveries.index', 'fulfilment.index', 'audit.index', 'analytics.index'] as $name) {
            $this->ok($this->get(route($name)), "page {$name}");
        }
    }

    public function test_order_advance_reject_cancel_delete(): void
    {
        $this->actingAs($this->admin);
        $product = $this->stockedProduct();
        $make = function () use ($product) {
            $this->ok($this->post(route('orders.store'), [
                'shop_id' => $this->shop->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ]), 'create order');

            return Order::latest('id')->firstOrFail();
        };

        $order = $make();
        $this->ok($this->post(route('orders.request-advance', $order), ['advance_amount' => 100]), 'request advance');
        $this->assertSame(Order::STATUS_AWAITING_ADVANCE, $order->fresh()->status);
        $this->ok($this->get(route('orders.show', $order)), 'show awaiting advance');
        $this->ok($this->post(route('orders.collect-advance', $order), ['method' => 'cash']), 'collect advance');
        $this->ok($this->post(route('orders.approve', $order), ['credit_override' => 1]), 'approve after advance');
        $this->assertSame(Order::STATUS_APPROVED, $order->fresh()->status);
        $this->ok($this->post(route('orders.cancel', $order), ['cancellation_reason' => 'customer changed mind']), 'cancel approved');
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);

        $order = $make();
        $this->ok($this->post(route('orders.reject', $order), ['rejection_reason' => 'bad']), 'reject');
        $this->assertSame(Order::STATUS_REJECTED, $order->fresh()->status);

        $order = $make();
        $this->ok($this->delete(route('orders.destroy', $order)), 'delete');
        $this->assertNull(Order::find($order->id));

        $this->ok($this->post(route('payments.store'), [
            'shop_id' => $this->shop->id, 'amount' => 50, 'method' => 'bank_transfer', 'reference' => 'X1',
        ]), 'record payment');
        $this->ok($this->post(route('payments.reject', Payment::latest('id')->first()), ['rejection_reason' => 'bounced']), 'reject payment');
    }

    public function test_procurement_and_inventory(): void
    {
        $this->actingAs($this->admin);
        $product = Product::where('status', Product::STATUS_ACTIVE)->firstOrFail();
        $supplier = Supplier::firstOrFail();
        $warehouse = Warehouse::firstOrFail();

        $this->ok($this->post(route('purchases.store'), [
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'exchange_rate' => 120,
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost_foreign' => 5]],
        ]), 'create purchase');
        $purchase = Purchase::latest('id')->firstOrFail();
        $this->ok($this->get(route('purchases.show', $purchase)), 'purchase page');

        $this->ok($this->post(route('purchases.shipment', $purchase), [
            'freight_cost' => 1000, 'warehouse_id' => $warehouse->id,
        ]), 'shipment from purchase');
        $shipment = Shipment::latest('id')->firstOrFail();
        $this->ok($this->post(route('shipments.costs', $shipment), ['freight_cost' => 1500, 'customs_duty' => 200]), 'update costs');
        $this->ok($this->post(route('shipments.arrive', $shipment)), 'arrive');
        $this->ok($this->post(route('shipments.receive', $shipment), ['warehouse_id' => $warehouse->id]), 'receive');
        $this->assertSame(Shipment::STATUS_RECEIVED, $shipment->fresh()->status);

        $this->ok($this->post(route('shipments.store'), [
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_cost_bdt' => 500]],
        ]), 'direct shipment');
        $this->ok($this->get(route('shipments.show', Shipment::latest('id')->first())), 'shipment page');

        $this->ok($this->post(route('inventory.receive.store'), [
            'warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'qty' => 5,
        ]), 'manual receive');
        $this->ok($this->get(route('inventory.ledger')), 'ledger');
    }

    public function test_master_data_crud(): void
    {
        $this->actingAs($this->admin);

        $this->ok($this->post(route('categories.store'), ['name' => 'Cables']), 'category store');
        $category = \App\Models\Category::latest('id')->first();
        $this->ok($this->put(route('categories.update', $category), ['name' => 'Cables & Wires', 'is_active' => 1]), 'category update');

        $this->ok($this->post(route('brands.store'), ['name' => 'NovaTech']), 'brand store');
        $brand = \App\Models\Brand::where('name', 'NovaTech')->firstOrFail();
        $this->ok($this->put(route('brands.update', $brand), ['name' => 'NovaTech Pro', 'is_active' => 1]), 'brand update');

        $this->ok($this->post(route('price-groups.store'), ['name' => 'VIP', 'code' => 'VIP']), 'price group store');
        $group = \App\Models\PriceGroup::where('code', 'VIP')->first();
        $this->ok($this->put(route('price-groups.update', $group), ['name' => 'VIP Dealers', 'code' => 'VIP', 'is_active' => 1]), 'price group update');

        $this->ok($this->post(route('products.store'), [
            'name' => 'Test Charger', 'sku' => 'TST-CHG-1', 'category_id' => $category->id, 'brand_id' => $brand->id,
            'wholesale_price' => 500, 'retail_price' => 650, 'status' => 'active',
            'group_prices' => [$group->id => 480],
        ]), 'product store');
        $product = Product::where('sku', 'TST-CHG-1')->firstOrFail();
        $this->ok($this->put(route('products.update', $product), [
            'name' => 'Test Charger 2', 'sku' => 'TST-CHG-1', 'wholesale_price' => 520, 'status' => 'active',
        ]), 'product update');
        $this->ok($this->delete(route('categories.destroy', \App\Models\Category::create(['name' => 'Tmp', 'slug' => 'tmp-x', 'is_active' => true]))), 'category delete');
        $this->ok($this->delete(route('brands.destroy', \App\Models\Brand::create(['name' => 'TmpBrand', 'slug' => 'tmp-brand-x', 'is_active' => true]))), 'brand delete');
        $this->from(route('categories.index'))
            ->delete(route('categories.destroy', $category))
            ->assertSessionHasErrors('category');
        $this->from(route('brands.index'))
            ->delete(route('brands.destroy', $brand))
            ->assertSessionHasErrors('brand');

        $this->ok($this->post(route('suppliers.store'), ['name' => 'Acme Ltd', 'code' => 'ACME']), 'supplier store');
        $supplier = Supplier::where('code', 'ACME')->first();
        $this->ok($this->put(route('suppliers.update', $supplier), ['name' => 'Acme Ltd 2', 'code' => 'ACME', 'is_active' => 1]), 'supplier update');

        $this->ok($this->post(route('warehouses.store'), ['code' => 'WH-T', 'name' => 'Test WH']), 'warehouse store');
        $wh = Warehouse::where('code', 'WH-T')->first();
        $this->ok($this->put(route('warehouses.update', $wh), ['code' => 'WH-T', 'name' => 'Test WH 2', 'is_active' => 1]), 'warehouse update');

        $this->ok($this->post(route('shops.store'), [
            'name' => 'Smoke Shop', 'owner_name' => 'Rahim', 'phone' => '01712345678',
            'status' => Shop::STATUS_PENDING, 'credit_limit' => 50000,
        ]), 'shop store');
        $shop = Shop::where('name', 'Smoke Shop')->firstOrFail();
        $this->ok($this->put(route('shops.update', $shop), [
            'name' => 'Smoke Shop 2', 'owner_name' => 'Rahim', 'phone' => '01712345678', 'status' => Shop::STATUS_PENDING,
        ]), 'shop update');
        $this->ok($this->post(route('shops.approve', $shop)), 'shop approve');
        $this->ok($this->post(route('shops.credentials', $shop), [
            'login_email' => 'smoke@shop.test', 'login_password' => '12345678', 'notify_whatsapp' => 0,
        ]), 'shop credentials');

        $this->ok($this->post(route('salesmen.store'), [
            'name' => 'Test Rep', 'email' => 'rep@field.test', 'password' => '12345678',
            'shop_ids' => [$shop->id], 'monthly_target' => 100000,
        ]), 'salesman store');
        $rep = User::where('email', 'rep@field.test')->firstOrFail();
        $this->ok($this->put(route('salesmen.update', $rep), ['name' => 'Test Rep 2', 'email' => 'rep@field.test']), 'salesman update');

        $this->ok($this->post(route('roles.store'), ['name' => 'Smoke Role', 'permissions' => ['orders.view']]), 'role store');
        $role = Role::where('name', 'Smoke Role')->firstOrFail();
        $this->ok($this->put(route('roles.update', $role), ['name' => 'Smoke Role 2', 'permissions' => ['orders.view', 'shops.view']]), 'role update');

        $this->ok($this->post(route('users.store'), [
            'name' => 'Ops User', 'email' => 'ops@bt.test', 'password' => 'Secret123!', 'password_confirmation' => 'Secret123!',
            'portal' => 'admin', 'roles' => ['Smoke Role 2'], 'scope_type' => 'global', 'is_active' => 1,
        ]), 'user store');
        $user = User::where('email', 'ops@bt.test')->firstOrFail();
        $this->ok($this->put(route('users.update', $user), [
            'name' => 'Ops User 2', 'email' => 'ops@bt.test', 'portal' => 'admin', 'roles' => ['Smoke Role 2'], 'scope_type' => 'global', 'is_active' => 1,
        ]), 'user update');
        $this->ok($this->post(route('users.toggle-active', $user)), 'user toggle');
        $this->ok($this->post(route('users.reset-password', $user), ['password' => 'Another123!', 'password_confirmation' => 'Another123!']), 'user reset');
        $this->ok($this->delete(route('roles.destroy', Role::create(['name' => 'Disposable', 'guard_name' => 'web']))), 'role delete');
    }

    public function test_targets_rewards_commission_rules(): void
    {
        $this->actingAs($this->admin);

        $this->ok($this->post(route('targets.store'), [
            'salesman_id' => $this->salesman->id, 'year' => now()->year, 'month' => now()->month, 'target_amount' => 200000,
        ]), 'target store');
        $this->ok($this->post(route('targets.seed')), 'targets seed');
        $this->ok($this->post(route('targets.recalculate', SalesTarget::latest('id')->first())), 'target recalc');

        $this->ok($this->post(route('rewards.store'), [
            'salesman_id' => $this->salesman->id, 'title' => 'Best month', 'type' => 'manual',
            'year' => now()->year, 'month' => now()->month, 'amount' => 5000,
        ]), 'reward store');
        $reward = Reward::latest('id')->first();
        $this->ok($this->post(route('rewards.award', $reward)), 'reward award');
        $this->ok($this->post(route('rewards.pay', $reward)), 'reward pay');
        $this->ok($this->post(route('rewards.store'), [
            'salesman_id' => $this->salesman->id, 'title' => 'Bonus', 'type' => 'manual',
            'year' => now()->year, 'month' => now()->month, 'amount' => 100,
        ]), 'reward store 2');
        $this->ok($this->post(route('rewards.cancel', Reward::latest('id')->first()), ['reason' => 'dup']), 'reward cancel');

        $this->ok($this->post(route('commissions.rule'), [
            'basis' => 'collection',
            'collection_rate_percent' => 2.5,
            'target_bonus_percent' => 1,
        ]), 'commission rule');
        $this->ok($this->post(route('commissions.sync')), 'commission sync');
        $accrued = Commission::where('status', Commission::STATUS_ACCRUED)->first();
        if ($accrued) {
            $this->ok($this->post(route('commissions.reject', $accrued), ['reason' => 'test']), 'commission reject');
        }

        foreach (['targets.index', 'rewards.index', 'commissions.index', 'reports.index'] as $name) {
            $this->ok($this->get(route($name)), "page {$name}");
        }
    }

    public function test_public_site_and_partner_leads(): void
    {
        $this->ok($this->post(route('site.contact.store'), [
            'name' => 'Visitor', 'email' => 'v@example.com', 'message' => 'Hello there',
        ]), 'contact form');
        $this->ok($this->post(route('site.partner.store'), [
            'business_name' => 'New Mobile House', 'contact_name' => 'Jamal', 'email' => 'jamal@nmh.test',
            'phone' => '01812345678', 'city' => 'Dhaka', 'business_type' => 'retailer',
        ]), 'partner form');

        $this->actingAs($this->admin);
        $inquiry = PartnerInquiry::where('email', 'jamal@nmh.test')->firstOrFail();
        $this->ok($this->get(route('partner-inquiries.index')), 'leads page');
        $this->ok($this->post(route('partner-inquiries.pending', $inquiry)), 'lead pending');
        $this->ok($this->post(route('partner-inquiries.accept', $inquiry)), 'lead accept');
        $this->assertSame(PartnerInquiry::STATUS_CONVERTED, $inquiry->fresh()->status);
        $this->ok($this->post(route('partner-inquiries.send-whatsapp', $inquiry)), 'lead whatsapp');

        $other = PartnerInquiry::where('status', PartnerInquiry::STATUS_NEW)->first();
        if ($other) {
            $this->ok($this->post(route('partner-inquiries.reject', $other), ['admin_notes' => 'no']), 'lead reject');
        }
        $this->ok($this->put(route('contact-messages.update', ContactMessage::latest('id')->first()), ['status' => 'read']), 'contact read');
        $this->ok($this->post(route('notifications.read-all')), 'notifications read all');
    }

    public function test_shop_portal_order_lifecycle(): void
    {
        $this->actingAs($this->shopUser);
        $product = $this->stockedProduct();
        $product->forceFill(['is_published' => true])->save();

        $this->ok($this->post(route('portal.orders.store'), [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'notes' => 'urgent',
        ]), 'portal place order');
        $order = Order::where('shop_id', $this->shop->id)->latest('id')->firstOrFail();
        $this->assertTrue($order->canPartnerEdit());

        $this->ok($this->get(route('portal.orders.edit', $order)), 'portal edit page');
        $this->ok($this->put(route('portal.orders.update', $order), [
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ]), 'portal update order');
        $this->assertSame(3, (int) $order->fresh()->items()->sum('quantity'));
        $this->ok($this->delete(route('portal.orders.destroy', $order)), 'portal delete order');
        $this->assertNull(Order::find($order->id));
    }

    public function test_field_visit_and_order(): void
    {
        $this->actingAs($this->salesman);
        $shop = Shop::where('assigned_salesman_id', $this->salesman->id)->firstOrFail();
        $product = $this->stockedProduct();
        $product->forceFill(['is_published' => true])->save();

        ShopVisit::where('salesman_id', $this->salesman->id)->whereNull('checked_out_at')->get()
            ->each(fn ($v) => $v->forceFill(['checked_out_at' => now()])->save());

        $this->ok($this->post(route('field.shops.check-in', $shop), ['purpose' => 'order', 'latitude' => 23.8, 'longitude' => 90.4]), 'check in');
        $visit = ShopVisit::where('salesman_id', $this->salesman->id)->latest('id')->firstOrFail();
        $this->ok($this->get(route('field.visit.show', $visit)), 'visit page');
        $this->ok($this->post(route('field.visit.order', $visit), [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'notes' => 'Talked about restock of chargers.',
        ]), 'visit order');

        $visit->refresh();
        $this->assertFalse($visit->isOpen(), 'Visit should close automatically once the order is sent.');
        $this->assertSame(ShopVisit::OUTCOME_ORDER_TAKEN, $visit->outcome);
        $this->assertNotNull($visit->order_id);
        $this->assertStringContainsString('chargers', (string) $visit->notes);

        $this->ok($this->get(route('field.visit.show', $visit)), 'closed visit page');
        $this->ok($this->get(route('field.orders.show', $visit->order_id)), 'order page');
        $this->get(route('field.orders', ['status' => 'waiting']))->assertOk()->assertSee($visit->order->number);
        $this->get(route('field.orders', ['status' => 'delivered']))->assertOk()->assertDontSee($visit->order->number);
        $this->ok($this->get(route('field.shops')), 'shops page');
        $this->ok($this->get(route('field.earnings')), 'earnings page');
        $this->ok($this->get(route('field.dashboard')), 'field dashboard');

        $this->ok($this->post(route('field.shops.check-in', $shop), ['purpose' => 'follow_up']), 'second check in');
        $second = ShopVisit::where('salesman_id', $this->salesman->id)->latest('id')->firstOrFail();
        $this->assertNotSame($visit->id, $second->id);
        $this->get(route('field.visit.show', $second))->assertOk()->assertSee('Review order');
        Storage::fake('public');
        $this->ok($this->post(route('field.visit.checkout', $second), [
            'outcome' => 'no_order',
            'notes' => 'Owner away',
            'photo' => UploadedFile::fake()->image('visit.jpg', 640, 480),
        ]), 'check out without order');
        $this->assertFalse($second->fresh()->isOpen());
    }

    public function test_salesman_adds_shop_and_admin_sees_field_activity(): void
    {
        $this->actingAs($this->salesman);
        ShopVisit::where('salesman_id', $this->salesman->id)->whereNull('checked_out_at')->get()
            ->each(fn ($v) => $v->forceFill(['checked_out_at' => now()])->save());

        $this->ok($this->get(route('field.shops.create')), 'add shop form');
        $this->ok($this->post(route('field.shops.store'), [
            'name' => 'Field Test Mobile Corner',
            'owner_name' => 'Rafiq Ahmed',
            'phone' => '01987654321',
            'city' => 'Mirpur',
            'notes' => 'Sells phone accessories',
            'latitude' => 23.8069,
            'longitude' => 90.3687,
            'check_in' => '1',
        ]), 'store shop with check-in');

        $shop = Shop::where('name', 'Field Test Mobile Corner')->firstOrFail();
        $this->assertSame(Shop::STATUS_ACTIVE, $shop->status);
        $this->assertNotNull($shop->approved_at);
        $this->assertSame($this->salesman->id, $shop->assigned_salesman_id);
        $this->assertSame($this->salesman->id, $shop->created_by);
        $this->assertEqualsWithDelta(23.8069, $shop->latitude, 0.0001);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->admin->id, 'notifiable_type' => User::class]);

        $visit = ShopVisit::where('salesman_id', $this->salesman->id)->where('shop_id', $shop->id)->firstOrFail();
        $this->assertTrue($visit->isOpen(), 'Adding with check-in should start a visit.');
        $this->get(route('field.visit.show', $visit))->assertOk()->assertSee('Review order');
        Storage::fake('public');
        $this->ok($this->post(route('field.visit.checkout', $visit), [
            'outcome' => 'follow_up',
            'notes' => 'Interested',
            'photo' => UploadedFile::fake()->image('shop.jpg', 640, 480),
        ]), 'end visit');

        $this->post(route('field.shops.store'), [
            'name' => 'Duplicate', 'owner_name' => 'Someone', 'phone' => '01987654321',
        ])->assertSessionHasErrors('phone');

        $this->ok($this->post(route('field.shops.store'), [
            'sells' => ['Mobile phones', 'Accessories'], 'interest' => 'warm',
        ]), 'store shop with no required fields');
        $quick = Shop::where('created_by', $this->salesman->id)->latest('id')->firstOrFail();
        $this->assertStringStartsWith('New shop SHP-', $quick->name);
        $this->assertSame(Shop::STATUS_ACTIVE, $quick->status);
        $this->assertStringContainsString('Sells: Mobile phones, Accessories', (string) $quick->notes);
        $this->assertStringContainsString('Interest: Interested', (string) $quick->notes);

        $this->get(route('field.shops'))->assertOk()->assertSee('Field Test Mobile Corner')->assertSee('Active');
        $this->ok($this->get(route('field.dashboard')), 'dashboard with field shop');

        $this->actingAs($this->admin);
        $this->get(route('visits.index', ['period' => 'today']))->assertOk()
            ->assertSee('By salesman')->assertSee($this->salesman->name)->assertSee('Field Test Mobile Corner');
        $this->get(route('visits.index', ['period' => 'month', 'salesman_id' => $this->salesman->id]))->assertOk();
        $this->get(route('shops.index', ['source' => 'field']))->assertOk()->assertSee('Added by salesmen');
        $this->get(route('shops.show', $shop))->assertOk()->assertSee('Added in the field by '.$this->salesman->name, false);
        $this->get(route('salesmen.show', $this->salesman))->assertOk()
            ->assertSee('Shops added in the field')->assertSee('Field Test Mobile Corner');

        $this->ok($this->post(route('shops.deactivate', $shop)), 'admin deactivate shop');
        $this->assertSame(Shop::STATUS_ON_HOLD, $shop->fresh()->status);
        $this->ok($this->post(route('shops.approve', $shop)), 'admin re-approve shop');
        $this->assertSame(Shop::STATUS_ACTIVE, $shop->fresh()->status);

        $this->actingAs($this->salesman);
        $this->ok($this->post(route('field.shops.check-in', $shop)), 'check in approved shop');
        $next = ShopVisit::where('salesman_id', $this->salesman->id)->latest('id')->firstOrFail();
        $this->get(route('field.visit.show', $next))->assertOk()->assertSee('Review order');
    }

    public function test_salesman_applies_for_partner_and_admin_accepts_existing_shop(): void
    {
        $this->actingAs($this->salesman);
        $this->ok($this->post(route('field.shops.store'), [
            'name' => 'Partner Candidate Store', 'owner_name' => 'Jamal Uddin', 'phone' => '01712340987', 'city' => 'Uttara',
        ]), 'store candidate shop');
        $shop = Shop::where('name', 'Partner Candidate Store')->firstOrFail();
        $shopCount = Shop::count();

        $this->get(route('field.partners.create', ['shop' => $shop->id]))->assertOk()
            ->assertSee('New partner application')->assertSee('Partner Candidate Store');

        $this->post(route('field.partners.store'), [
            'business_name' => 'Staff email', 'email' => $this->admin->email, 'phone' => '01712340987',
        ])->assertSessionHasErrors('email');

        $this->ok($this->post(route('field.partners.store'), [
            'shop_id' => $shop->id,
            'business_name' => 'Partner Candidate Store',
            'email' => 'Jamal.Store@Example.com',
            'phone' => '01712340987',
            'city' => 'Uttara',
            'business_type' => 'retailer',
        ]), 'submit field partner application');

        $inquiry = PartnerInquiry::where('submitted_by', $this->salesman->id)->latest('id')->firstOrFail();
        $this->assertSame(PartnerInquiry::SOURCE_FIELD, $inquiry->source);
        $this->assertSame($shop->id, $inquiry->shop_id);
        $this->assertSame('jamal.store@example.com', $inquiry->email);
        $this->assertSame('', $inquiry->contact_name);
        $this->assertTrue($inquiry->isPending());

        $this->post(route('field.partners.store'), [
            'business_name' => 'Again', 'email' => 'jamal.store@example.com', 'phone' => '01800000000',
        ])->assertSessionHasErrors();

        $this->get(route('field.partners'))->assertOk()->assertSee('Partner Candidate Store')->assertSee('Waiting');

        $this->actingAs($this->admin);
        $this->get(route('partner-inquiries.index', ['status' => 'pending']))->assertOk()
            ->assertSee('Field · '.$this->salesman->name, false);
        $this->ok($this->post(route('partner-inquiries.accept', $inquiry)), 'accept field application');

        $this->assertSame(PartnerInquiry::STATUS_CONVERTED, $inquiry->fresh()->status);
        $this->assertSame($shopCount, Shop::count(), 'Accepting should upgrade the existing shop, not create a new one.');
        $shop->refresh();
        $this->assertSame(Shop::STATUS_ACTIVE, $shop->status);
        $this->assertFalse($shop->users()->exists(), 'Field applications wait for the salesman to create the login.');
        $this->assertTrue($inquiry->fresh()->needsLogin());
        $this->get(route('partner-inquiries.index', ['source' => 'field']))->assertOk()
            ->assertSee('Waiting for '.$this->salesman->name, false)->assertSee('Issue login');
        $this->post(route('partner-inquiries.send-whatsapp', $inquiry))->assertSessionHas('error');

        $otherSalesman = User::where('portal', User::PORTAL_SALESMAN)->where('id', '!=', $this->salesman->id)->first();
        if ($otherSalesman) {
            $this->actingAs($otherSalesman)->get(route('field.partners.login', $inquiry))->assertForbidden();
        }

        $this->actingAs($this->salesman);
        $this->get(route('field.dashboard'))->assertOk()->assertSee('create the owner', false);
        $this->get(route('field.partners'))->assertOk()->assertSee('Accepted')->assertSee('Needs login');
        $this->get(route('field.partners.login', $inquiry))->assertOk()->assertSee('Create partner login');

        $this->post(route('field.partners.login.store', $inquiry), ['email' => $this->admin->email, 'password' => 'Secret123'])
            ->assertSessionHasErrors('email');
        $this->post(route('field.partners.login.store', $inquiry), ['email' => 'jamal.store@example.com', 'password' => 'short'])
            ->assertSessionHasErrors('password');

        $this->post(route('field.partners.login.store', $inquiry), ['email' => 'Jamal.Store@example.com', 'password' => 'Jamal2026!'])
            ->assertRedirect(route('field.partners.login', $inquiry))
            ->assertSessionHas('login_result', fn ($r) => $r['ok'] === true && $r['email'] === 'jamal.store@example.com');
        $this->get(route('field.partners.login', $inquiry))->assertOk()
            ->assertSee('Login works')->assertSee('Jamal2026!');
        $this->assertSame('jamal.store@example.com', $inquiry->fresh()->portal_email);
        $this->assertTrue($shop->users()->where('email', 'jamal.store@example.com')->exists());

        $this->get(route('field.partners.create'))->assertOk()->assertDontSee('Partner Candidate Store · ');

        $this->ok($this->post(route('field.partners.login.store', $inquiry), ['email' => 'jamal.store@example.com', 'password' => 'NewPass2026']), 'reset owner password');
        $this->assertSame(1, $shop->users()->count());

        $this->actingAs($this->admin);
        $this->get(route('partner-inquiries.index'))->assertOk()
            ->assertSee('set by '.$this->salesman->name, false)->assertSee('Website');

        auth()->logout();
        $this->post(route('portal.login.submit'), ['email' => 'jamal.store@example.com', 'password' => 'NewPass2026'])
            ->assertRedirect(route('portal.dashboard'));
        $this->assertAuthenticated();
        $this->assertSame('jamal.store@example.com', auth()->user()->email);
    }
}
