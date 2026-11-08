<?php

use App\Http\Controllers\AccountPasswordController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\AnnouncementController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CommissionController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DeliveryController;
use App\Http\Controllers\Admin\DueManagementController;
use App\Http\Controllers\Admin\ProductOfferController;
use App\Http\Controllers\Admin\SalaryController;
use App\Http\Controllers\Admin\FulfilmentController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\PartnerInquiryController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PriceGroupController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\PurchaseController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ReturnController;
use App\Http\Controllers\Admin\RewardController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SalesmanController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\WebsiteController;
use App\Http\Controllers\Admin\ShipmentController;
use App\Http\Controllers\Admin\ShopController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\TargetController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VisitController;
use App\Http\Controllers\Admin\WarehouseController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Field\FieldPortalController;
use App\Http\Controllers\Field\SalesmanAuthController;
use App\Http\Controllers\Portal\ShopAuthController;
use App\Http\Controllers\Portal\ShopPortalController;
use App\Http\Controllers\Site\FaviconController;
use App\Http\Controllers\Site\SiteController;
use App\Http\Middleware\EnsureAdminPortal;
use App\Http\Middleware\EnsureSalesmanPortal;
use App\Http\Middleware\EnsureShopPortal;
use Illuminate\Support\Facades\Route;

Route::get('/favicon.ico', [FaviconController::class, 'ico'])->name('favicon.ico');
Route::get('/favicon-{size}.png', [FaviconController::class, 'png'])->whereNumber('size')->name('favicon.png');

Route::get('/', [SiteController::class, 'home'])->name('site.home');
Route::get('/products', [SiteController::class, 'products'])->name('site.products');
Route::get('/products/{product}', [SiteController::class, 'showProduct'])->name('site.products.show');
Route::get('/about', [SiteController::class, 'about'])->name('site.about');
Route::get('/contact', [SiteController::class, 'contact'])->name('site.contact');
Route::post('/contact', [SiteController::class, 'storeContact'])->middleware('throttle:5,1')->name('site.contact.store');
Route::get('/become-a-partner', [SiteController::class, 'partner'])->name('site.partner');
Route::post('/become-a-partner', [SiteController::class, 'storePartner'])->middleware('throttle:5,1')->name('site.partner.store');

Route::prefix('portal')->name('portal.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/login', [ShopAuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [ShopAuthController::class, 'login'])->middleware('throttle:login')->name('login.submit');
    });

    Route::middleware(['auth', EnsureShopPortal::class])->group(function () {
        Route::post('/logout', [ShopAuthController::class, 'logout'])->name('logout');
        Route::get('/', fn () => redirect()->route('portal.dashboard'));
        Route::get('/dashboard', [ShopPortalController::class, 'dashboard'])->name('dashboard');
        Route::get('/products', [ShopPortalController::class, 'products'])->name('products');
        Route::get('/orders', [ShopPortalController::class, 'orders'])->name('orders');
        Route::get('/orders/create', [ShopPortalController::class, 'createOrder'])->name('orders.create');
        Route::post('/orders', [ShopPortalController::class, 'storeOrder'])->name('orders.store');
        Route::get('/orders/{order}/edit', [ShopPortalController::class, 'editOrder'])->name('orders.edit');
        Route::put('/orders/{order}', [ShopPortalController::class, 'updateOrder'])->name('orders.update');
        Route::delete('/orders/{order}', [ShopPortalController::class, 'destroyOrder'])->name('orders.destroy');
        Route::get('/orders/{order}', [ShopPortalController::class, 'showOrder'])->name('orders.show');
        Route::post('/orders/{order}/pay', [ShopPortalController::class, 'payBill'])->middleware('throttle:20,1')->name('orders.pay');
        Route::get('/profile', [ShopPortalController::class, 'profile'])->name('profile');
        Route::get('/statement', [ShopPortalController::class, 'statement'])->name('statement');
        Route::get('/account/password', [AccountPasswordController::class, 'edit'])->name('account.password');
        Route::put('/account/password', [AccountPasswordController::class, 'update'])->middleware('throttle:6,1')->name('account.password.update');
    });
});

Route::prefix('field')->name('field.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/login', [SalesmanAuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [SalesmanAuthController::class, 'login'])->middleware('throttle:login')->name('login.submit');
    });

    Route::middleware(['auth', EnsureSalesmanPortal::class])->group(function () {
        Route::post('/logout', [SalesmanAuthController::class, 'logout'])->name('logout');
        Route::get('/', fn () => redirect()->route('field.dashboard'));
        Route::get('/dashboard', [FieldPortalController::class, 'dashboard'])->name('dashboard');
        Route::get('/shops', [FieldPortalController::class, 'shops'])->name('shops');
        Route::get('/shops/new', [FieldPortalController::class, 'createShop'])->name('shops.create');
        Route::get('/geo/divisions', [FieldPortalController::class, 'geoDivisions'])->name('geo.divisions');
        Route::get('/geo/districts', [FieldPortalController::class, 'geoDistricts'])->name('geo.districts');
        Route::get('/geo/thanas', [FieldPortalController::class, 'geoThanas'])->name('geo.thanas');
        Route::get('/geo/post-offices', [FieldPortalController::class, 'geoPostOffices'])->name('geo.post-offices');
        Route::get('/geo/locations', [FieldPortalController::class, 'geoLocations'])->name('geo.locations');
        Route::post('/shops', [FieldPortalController::class, 'storeShop'])->middleware('throttle:30,1')->name('shops.store');
        Route::get('/shops/{shop}/login', [FieldPortalController::class, 'shopLogin'])->name('shops.login');
        Route::post('/shops/{shop}/login', [FieldPortalController::class, 'storeShopLogin'])->middleware('throttle:20,1')->name('shops.login.store');
        Route::post('/shops/{shop}/approve', [FieldPortalController::class, 'approveShop'])->name('shops.approve');
        Route::post('/shops/{shop}/check-in', [FieldPortalController::class, 'checkIn'])->name('shops.check-in');
        Route::post('/shops/{shop}/order', [FieldPortalController::class, 'placeOrder'])->name('shops.order');
        Route::get('/visits', [FieldPortalController::class, 'visitsLog'])->name('visits');
        Route::get('/visits/{visit}', [FieldPortalController::class, 'showVisit'])->name('visit.show');
        Route::post('/visits/{visit}/order', [FieldPortalController::class, 'submitOrder'])->name('visit.order');
        Route::post('/visits/{visit}/check-out', [FieldPortalController::class, 'checkOut'])->name('visit.checkout');
        Route::post('/visits/{visit}/leave', [FieldPortalController::class, 'leaveVisit'])->name('visit.leave');
        Route::get('/orders', [FieldPortalController::class, 'orders'])->name('orders');
        Route::get('/orders/create', [FieldPortalController::class, 'createOrder'])->name('orders.create');
        Route::redirect('/orders/new', '/field/orders/create');
        Route::get('/orders/{order}', [FieldPortalController::class, 'showOrder'])->whereNumber('order')->name('orders.show');
        Route::post('/orders/{order}/collect', [FieldPortalController::class, 'collectBill'])->middleware('throttle:20,1')->whereNumber('order')->name('orders.collect');
        Route::post('/orders/{order}/deliver', [FieldPortalController::class, 'deliverOrder'])->middleware('throttle:20,1')->whereNumber('order')->name('orders.deliver');
        Route::post('/orders/{order}/discount', [FieldPortalController::class, 'discountOrder'])->middleware('throttle:20,1')->whereNumber('order')->name('orders.discount');
        Route::get('/earnings', [FieldPortalController::class, 'earnings'])->name('earnings');
        Route::get('/collections', [FieldPortalController::class, 'collections'])->name('collections');
        Route::get('/deliveries', [FieldPortalController::class, 'deliveries'])->name('deliveries');
        Route::get('/deliveries/stock', [FieldPortalController::class, 'deliveryStock'])->name('deliveries.stock');
        Route::get('/deliveries/{delivery}', [FieldPortalController::class, 'showDelivery'])->whereNumber('delivery')->name('deliveries.show');
        Route::post('/deliveries/{delivery}/receive', [FieldPortalController::class, 'receiveDelivery'])->middleware('throttle:30,1')->whereNumber('delivery')->name('deliveries.receive');
        Route::post('/deliveries/{delivery}/quantities', [FieldPortalController::class, 'updateDeliveryQuantities'])->middleware('throttle:30,1')->whereNumber('delivery')->name('deliveries.quantities');
        Route::post('/deliveries/{delivery}/return', [FieldPortalController::class, 'returnDeliveryStock'])->middleware('throttle:30,1')->whereNumber('delivery')->name('deliveries.return');
        Route::get('/announcements', [FieldPortalController::class, 'announcements'])->name('announcements');
        Route::get('/notifications', [FieldPortalController::class, 'notifications'])->name('notifications');
        Route::post('/notifications/{id}/read', [FieldPortalController::class, 'markNotificationRead'])->name('notifications.read');
        Route::get('/partners', [FieldPortalController::class, 'partners'])->name('partners');
        Route::get('/partners/apply', [FieldPortalController::class, 'createPartner'])->name('partners.create');
        Route::post('/partners', [FieldPortalController::class, 'storePartner'])->middleware('throttle:20,1')->name('partners.store');
        Route::get('/partners/{inquiry}/login', [FieldPortalController::class, 'partnerLogin'])->name('partners.login');
        Route::post('/partners/{inquiry}/login', [FieldPortalController::class, 'storePartnerLogin'])->middleware('throttle:20,1')->name('partners.login.store');
        Route::get('/account/password', [AccountPasswordController::class, 'edit'])->name('account.password');
        Route::put('/account/password', [AccountPasswordController::class, 'update'])->middleware('throttle:6,1')->name('account.password.update');
    });
});

Route::prefix('admin')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login.submit');
    });

    Route::middleware(['auth', EnsureAdminPortal::class])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('/', fn () => redirect()->route('dashboard'));
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::get('/account/password', [AccountPasswordController::class, 'edit'])->name('account.password');
        Route::put('/account/password', [AccountPasswordController::class, 'update'])->middleware('throttle:6,1')->name('account.password.update');

        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

        // Phase 1 — RBAC
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle-active');
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');

        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::get('/roles/create', [RoleController::class, 'create'])->name('roles.create');
        Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
        Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
        Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');

        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit.index');

        // Phase 2 — Shops, catalogue, pricing
        Route::get('/shops', [ShopController::class, 'index'])->name('shops.index');
        Route::get('/shops/create', [ShopController::class, 'create'])->name('shops.create');
        Route::post('/shops', [ShopController::class, 'store'])->name('shops.store');
        Route::get('/shops/{shop}', [ShopController::class, 'show'])->name('shops.show');
        Route::get('/shops/{shop}/edit', [ShopController::class, 'edit'])->name('shops.edit');
        Route::put('/shops/{shop}', [ShopController::class, 'update'])->name('shops.update');
        Route::post('/shops/{shop}/approve', [ShopController::class, 'approve'])->name('shops.approve');
        Route::post('/shops/{shop}/deactivate', [ShopController::class, 'deactivate'])->name('shops.deactivate');
        Route::post('/shops/{shop}/credentials', [ShopController::class, 'issueCredentials'])->name('shops.credentials');
        Route::get('/shops/{shop}/statement', [ShopController::class, 'statement'])->name('shops.statement');

        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
        Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');

        Route::get('/offers', [ProductOfferController::class, 'index'])->name('offers.index');
        Route::post('/offers', [ProductOfferController::class, 'store'])->name('offers.store');
        Route::put('/offers/{offer}', [ProductOfferController::class, 'update'])->name('offers.update');
        Route::delete('/offers/{offer}', [ProductOfferController::class, 'destroy'])->name('offers.destroy');

        Route::get('/dues', [DueManagementController::class, 'index'])->name('dues.index');
        Route::get('/dues/export', [DueManagementController::class, 'export'])->name('dues.export');

        Route::get('/salaries', [SalaryController::class, 'index'])->name('salaries.index');
        Route::post('/salaries', [SalaryController::class, 'storeSalary'])->name('salaries.store');
        Route::post('/salaries/pay', [SalaryController::class, 'pay'])->name('salaries.pay');

        Route::get('/announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
        Route::post('/announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
        Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy'])->name('announcements.destroy');

        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

        Route::get('/brands', [BrandController::class, 'index'])->name('brands.index');
        Route::post('/brands', [BrandController::class, 'store'])->name('brands.store');
        Route::put('/brands/{brand}', [BrandController::class, 'update'])->name('brands.update');
        Route::delete('/brands/{brand}', [BrandController::class, 'destroy'])->name('brands.destroy');

        Route::get('/price-groups', [PriceGroupController::class, 'index'])->name('price-groups.index');
        Route::post('/price-groups', [PriceGroupController::class, 'store'])->name('price-groups.store');
        Route::put('/price-groups/{priceGroup}', [PriceGroupController::class, 'update'])->name('price-groups.update');

        // Phase 3 — Salesmen, visits, order collection
        Route::get('/salesmen', [SalesmanController::class, 'index'])->name('salesmen.index');
        Route::get('/salesmen/create', [SalesmanController::class, 'create'])->name('salesmen.create');
        Route::post('/salesmen', [SalesmanController::class, 'store'])->name('salesmen.store');
        Route::get('/salesmen/{salesman}', [SalesmanController::class, 'show'])->name('salesmen.show');
        Route::get('/salesmen/{salesman}/edit', [SalesmanController::class, 'edit'])->name('salesmen.edit');
        Route::put('/salesmen/{salesman}', [SalesmanController::class, 'update'])->name('salesmen.update');
        Route::post('/salesmen/{salesman}/reset-password', [SalesmanController::class, 'resetPassword'])->name('salesmen.reset-password');
        Route::get('/salesmen/{salesman}/login-logs', [SalesmanController::class, 'loginLogs'])->name('salesmen.login-logs');

        Route::get('/visits', [VisitController::class, 'index'])->name('visits.index');

        Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/create', [OrderController::class, 'create'])->name('orders.create');
        Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
        Route::get('/orders/{order}/preview', [OrderController::class, 'preview'])->name('orders.preview');
        Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('/orders/{order}/discount', [OrderController::class, 'discount'])->name('orders.discount');
        Route::post('/orders/{order}/approve', [OrderController::class, 'approve'])->name('orders.approve');
        Route::post('/orders/{order}/deliver', [OrderController::class, 'deliver'])->name('orders.deliver');
        Route::post('/orders/{order}/request-advance', [OrderController::class, 'requestAdvance'])->name('orders.request-advance');
        Route::post('/orders/{order}/collect-advance', [OrderController::class, 'collectAdvance'])->name('orders.collect-advance');
        Route::post('/orders/{order}/reject', [OrderController::class, 'reject'])->name('orders.reject');
        Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
        Route::delete('/orders/{order}', [OrderController::class, 'destroy'])->name('orders.destroy');

        // Phase 5 — Warehouses, inventory, fulfilment, delivery
        Route::get('/warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
        Route::post('/warehouses', [WarehouseController::class, 'store'])->name('warehouses.store');
        Route::put('/warehouses/{warehouse}', [WarehouseController::class, 'update'])->name('warehouses.update');

        Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
        Route::get('/inventory/receive', [InventoryController::class, 'receiveForm'])->name('inventory.receive');
        Route::post('/inventory/receive', [InventoryController::class, 'receive'])->name('inventory.receive.store');
        Route::get('/inventory/ledger', [InventoryController::class, 'ledger'])->name('inventory.ledger');

        Route::get('/fulfilment', [FulfilmentController::class, 'index'])->name('fulfilment.index');
        Route::get('/fulfilment/{fulfilment}/workspace', [FulfilmentController::class, 'workspace'])->name('fulfilment.workspace');
        Route::get('/fulfilment/{fulfilment}', [FulfilmentController::class, 'show'])->name('fulfilment.show');
        Route::post('/fulfilment/{fulfilment}/start-pick', [FulfilmentController::class, 'startPick'])->name('fulfilment.start-pick');
        Route::post('/fulfilment/{fulfilment}/complete-pick', [FulfilmentController::class, 'completePick'])->name('fulfilment.complete-pick');
        Route::post('/fulfilment/{fulfilment}/pack', [FulfilmentController::class, 'pack'])->name('fulfilment.pack');
        Route::post('/fulfilment/{fulfilment}/dispatch', [FulfilmentController::class, 'dispatch'])->name('fulfilment.dispatch');
        Route::post('/fulfilment/{fulfilment}/deliver', [FulfilmentController::class, 'markDelivered'])->name('fulfilment.deliver');

        Route::get('/deliveries', [DeliveryController::class, 'index'])->name('deliveries.index');
        Route::get('/deliveries/men', [DeliveryController::class, 'men'])->name('deliveries.men');
        Route::get('/deliveries/{delivery}', [DeliveryController::class, 'show'])->name('deliveries.show');
        Route::get('/deliveries/{delivery}/challan', [DeliveryController::class, 'challan'])->name('deliveries.challan');
        Route::post('/deliveries/{delivery}/assign', [DeliveryController::class, 'assign'])->name('deliveries.assign');
        Route::post('/deliveries/{delivery}/out', [DeliveryController::class, 'markOutForDelivery'])->name('deliveries.out');
        Route::post('/deliveries/{delivery}/deliver', [DeliveryController::class, 'markDelivered'])->name('deliveries.deliver');
        Route::post('/deliveries/{delivery}/fail', [DeliveryController::class, 'markFailed'])->name('deliveries.fail');
        Route::post('/deliveries/{delivery}/retry', [DeliveryController::class, 'retry'])->name('deliveries.retry');

        // Phase 6 — Suppliers, purchases, China shipments, landed cost
        Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');

        Route::get('/purchases', [PurchaseController::class, 'index'])->name('purchases.index');
        Route::get('/purchases/create', [PurchaseController::class, 'create'])->name('purchases.create');
        Route::post('/purchases', [PurchaseController::class, 'store'])->name('purchases.store');
        Route::get('/purchases/{purchase}', [PurchaseController::class, 'show'])->name('purchases.show');
        Route::post('/purchases/{purchase}/shipment', [PurchaseController::class, 'createShipment'])->name('purchases.shipment');

        Route::get('/shipments', [ShipmentController::class, 'index'])->name('shipments.index');
        Route::get('/shipments/create', [ShipmentController::class, 'create'])->name('shipments.create');
        Route::post('/shipments', [ShipmentController::class, 'store'])->name('shipments.store');
        Route::get('/shipments/{shipment}', [ShipmentController::class, 'show'])->name('shipments.show');
        Route::post('/shipments/{shipment}/costs', [ShipmentController::class, 'updateCosts'])->name('shipments.costs');
        Route::post('/shipments/{shipment}/arrive', [ShipmentController::class, 'markArrived'])->name('shipments.arrive');
        Route::post('/shipments/{shipment}/receive', [ShipmentController::class, 'receive'])->name('shipments.receive');

        // Phase 7 — Invoices, payments, credit, returns
        Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::get('/invoices/{invoice}/download', [InvoiceController::class, 'download'])->name('invoices.download');
        Route::post('/orders/{order}/invoice', [InvoiceController::class, 'storeFromOrder'])->name('orders.invoice');

        Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::get('/payments/create', [PaymentController::class, 'create'])->name('payments.create');
        Route::post('/payments', [PaymentController::class, 'store'])->name('payments.store');
        Route::post('/payments/{payment}/verify', [PaymentController::class, 'verify'])->name('payments.verify');
        Route::post('/payments/{payment}/reject', [PaymentController::class, 'reject'])->name('payments.reject');

        Route::get('/returns', [ReturnController::class, 'index'])->name('returns.index');
        Route::get('/returns/create', [ReturnController::class, 'create'])->name('returns.create');
        Route::post('/returns', [ReturnController::class, 'store'])->name('returns.store');
        Route::get('/returns/{productReturn}', [ReturnController::class, 'show'])->name('returns.show');
        Route::post('/returns/{productReturn}/approve', [ReturnController::class, 'approve'])->name('returns.approve');
        Route::post('/returns/{productReturn}/restock', [ReturnController::class, 'restock'])->name('returns.restock');
        Route::post('/returns/{productReturn}/reject', [ReturnController::class, 'reject'])->name('returns.reject');

        // Phase 8 — Targets, commissions, rewards
        Route::get('/targets', [TargetController::class, 'index'])->name('targets.index');
        Route::post('/targets', [TargetController::class, 'store'])->name('targets.store');
        Route::post('/targets/seed', [TargetController::class, 'seedMonth'])->name('targets.seed');
        Route::post('/targets/{target}/recalculate', [TargetController::class, 'recalculate'])->name('targets.recalculate');

        Route::get('/commissions', [CommissionController::class, 'index'])->name('commissions.index');
        Route::post('/commissions/sync', [CommissionController::class, 'sync'])->name('commissions.sync');
        Route::post('/commissions/rule', [CommissionController::class, 'updateRule'])->name('commissions.rule');
        Route::post('/commissions/{commission}/approve', [CommissionController::class, 'approve'])->name('commissions.approve');
        Route::post('/commissions/{commission}/pay', [CommissionController::class, 'pay'])->name('commissions.pay');
        Route::post('/commissions/{commission}/reject', [CommissionController::class, 'reject'])->name('commissions.reject');

        Route::get('/rewards', [RewardController::class, 'index'])->name('rewards.index');
        Route::post('/rewards', [RewardController::class, 'store'])->name('rewards.store');
        Route::post('/rewards/{reward}/award', [RewardController::class, 'award'])->name('rewards.award');
        Route::post('/rewards/{reward}/pay', [RewardController::class, 'pay'])->name('rewards.pay');
        Route::post('/rewards/{reward}/cancel', [RewardController::class, 'cancel'])->name('rewards.cancel');

        Route::get('/partner-leads', [PartnerInquiryController::class, 'index'])->name('partner-inquiries.index');
        Route::post('/partner-leads/{partnerInquiry}/accept', [PartnerInquiryController::class, 'accept'])->name('partner-inquiries.accept');
        Route::post('/partner-leads/{partnerInquiry}/reject', [PartnerInquiryController::class, 'reject'])->name('partner-inquiries.reject');
        Route::post('/partner-leads/{partnerInquiry}/pending', [PartnerInquiryController::class, 'pending'])->name('partner-inquiries.pending');
        Route::post('/partner-leads/{partnerInquiry}/issue-login', [PartnerInquiryController::class, 'issueLogin'])->name('partner-inquiries.issue-login');
        Route::post('/partner-leads/{partnerInquiry}/send-whatsapp', [PartnerInquiryController::class, 'sendWhatsapp'])->name('partner-inquiries.send-whatsapp');
        Route::put('/contact-messages/{contactMessage}', [PartnerInquiryController::class, 'markMessage'])->name('contact-messages.update');

        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/{report}', [ReportController::class, 'show'])->name('reports.show');
        Route::get('/reports/{report}/export', [ReportController::class, 'export'])->name('reports.export');
        Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');

        Route::get('/website', [WebsiteController::class, 'edit'])->name('website.edit');
        Route::put('/website', [WebsiteController::class, 'update'])->name('website.update');
        Route::post('/website/reset', [WebsiteController::class, 'reset'])->name('website.reset');
    });
});
