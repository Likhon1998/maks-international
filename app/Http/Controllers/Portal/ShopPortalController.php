<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shop;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ShopLedgerService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ShopPortalController extends Controller
{
    public function __construct(
        private OrderService $orders,
        private ShopLedgerService $ledger,
        private PaymentService $payments,
    ) {}

    public function dashboard(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        $productCount = Product::query()
            ->where('status', Product::STATUS_ACTIVE)
            ->where('is_published', true)
            ->count();

        $pendingOrders = Order::query()
            ->where('shop_id', $shop->id)
            ->where('status', Order::STATUS_PENDING_AUDIT)
            ->count();

        $recentOrders = Order::query()
            ->where('shop_id', $shop->id)
            ->latest('submitted_at')
            ->limit(5)
            ->get();

        return view('portal.dashboard', [
            'shop' => $shop,
            'productCount' => $productCount,
            'pendingOrders' => $pendingOrders,
            'recentOrders' => $recentOrders,
            'dueReminder' => (float) $shop->outstanding_balance > 0,
        ]);
    }

    public function products(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');
        $shop->load('priceGroup');

        $products = Product::query()
            ->with(['category', 'brand', 'prices', 'offers', 'images'])
            ->where('status', Product::STATUS_ACTIVE)
            ->where('is_published', true)
            ->orderBy('name')
            ->limit(400)
            ->get();

        $trendingIds = collect();
        try {
            $trendingIds = \App\Models\OrderItem::query()
                ->selectRaw('product_id, SUM(quantity) as sold')
                ->where('created_at', '>=', now()->subDays(60))
                ->groupBy('product_id')
                ->orderByDesc('sold')
                ->limit(24)
                ->pluck('sold', 'product_id');
        } catch (\Throwable) {
            $trendingIds = collect();
        }

        $newCutoff = now()->subDays(45);

        $catalog = $products->map(function (Product $product) use ($shop, $trendingIds, $newCutoff) {
            $price = (float) $product->priceForGroup($shop->priceGroup);
            $stock = (int) $product->availableStock();
            $isNew = $product->created_at && $product->created_at->gte($newCutoff);
            $sold = (int) ($trendingIds[$product->id] ?? 0);

            return [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'category_id' => $product->category_id,
                'category' => $product->category?->name ?: 'General',
                'stock' => $stock,
                'price' => $price,
                'price_label' => \App\Support\DemoData::taka($price),
                'image' => $product->imageUrl(),
                'images' => $product->imageUrls(),
                'in_stock' => $stock > 0,
                'is_new' => $isNew,
                'is_trending' => $sold > 0 || $stock >= 200,
                'sold' => $sold,
                'low_stock' => $stock > 0 && $stock <= max(5, (int) $product->minimum_stock),
            ];
        })->values();

        $posters = [
            [
                'eyebrow' => 'Wholesale desk',
                'title' => 'Order faster for '.$shop->name,
                'text' => 'Browse your price list, save a wishlist, and send orders for audit in one flow.',
                'tone' => 'violet',
                'cta' => 'Shop new arrivals',
                'filter' => 'new',
            ],
            [
                'eyebrow' => 'Trending now',
                'title' => 'What partners are ordering',
                'text' => 'Stock up on high-demand items before the next cycle.',
                'tone' => 'ink',
                'cta' => 'View trending',
                'filter' => 'trending',
            ],
            [
                'eyebrow' => 'Order freely',
                'title' => 'Buy the quantity you need',
                'text' => 'Your wholesale prices apply automatically. No credit-limit block on cart size.',
                'tone' => 'sand',
                'cta' => 'Browse all',
                'filter' => 'all',
            ],
        ];

        return view('portal.products.index', [
            'shop' => $shop,
            'catalog' => $catalog,
            'categories' => Category::query()->where('is_active', true)->orderBy('name')->get(),
            'credit' => null,
            'storeUrl' => route('portal.orders.store'),
            'initialSearch' => (string) $request->get('search', ''),
            'initialCategory' => (string) $request->get('category_id', ''),
            'posters' => $posters,
        ]);
    }

    public function createOrder(Request $request)
    {
        return redirect()->route('portal.products');
    }

    public function storeOrder(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $items = collect($data['items'])
            ->filter(fn ($row) => (int) ($row['quantity'] ?? 0) > 0)
            ->values()
            ->all();

        if ($items === []) {
            return back()->withErrors(['items' => 'Add at least one product to your cart.']);
        }

        $order = $this->orders->placeFromShopPortal(
            $request->user(),
            $shop,
            $items,
            $data['notes'] ?? null,
        );

        return redirect()->route('portal.orders.show', $order)
            ->with('success', 'Order '.$order->number.' submitted for Super Admin audit.');
    }

    public function editOrder(Request $request, Order $order)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');
        abort_unless($order->shop_id === $shop->id, 404);
        abort_unless($order->canPartnerEdit(), 403, 'This order can no longer be edited.');

        $shop->load('priceGroup');
        $order->load('items');

        $products = Product::query()
            ->with('prices')
            ->where('status', Product::STATUS_ACTIVE)
            ->where('is_published', true)
            ->orderBy('name')
            ->get();

        $quantities = $order->items->mapWithKeys(
            fn ($item) => [(int) $item->product_id => (int) $item->quantity]
        )->all();

        return view('portal.orders.edit', compact('shop', 'products', 'order', 'quantities'));
    }

    public function updateOrder(Request $request, Order $order)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');
        abort_unless($order->shop_id === $shop->id, 404);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['nullable', 'integer', 'min:0'],
        ]);

        $order = $this->orders->updatePendingFromShopPortal(
            $request->user(),
            $shop,
            $order,
            $data['items'],
            $data['notes'] ?? null,
        );

        return redirect()->route('portal.orders.show', $order)
            ->with('success', 'Order '.$order->number.' updated. Still waiting for Super Admin audit.');
    }

    public function destroyOrder(Request $request, Order $order)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');
        abort_unless($order->shop_id === $shop->id, 404);
        abort_unless($order->canDeleteBeforeApproval(), 403, 'This order can no longer be deleted.');

        $number = $order->number;
        $this->orders->deleteBeforeApproval($order, $request->user());

        return redirect()->route('portal.orders')
            ->with('success', 'Order '.$number.' deleted before approval.');
    }

    public function orders(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        $orders = Order::query()
            ->where('shop_id', $shop->id)
            ->latest('submitted_at')
            ->paginate(15);

        return view('portal.orders.index', compact('shop', 'orders'));
    }

    public function showOrder(Request $request, Order $order)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');
        abort_unless($order->shop_id === $shop->id, 404);

        $order->load(['items', 'salesman', 'auditor', 'advanceInvoice', 'invoice', 'delivery.assignee']);
        $order->setRelation('shop', $shop);

        return view('portal.orders.show', [
            'shop' => $shop,
            'order' => $order,
            'pendingBill' => $order->pendingBillPayment(),
            'collectibleBalance' => $order->collectibleBalance(),
        ]);
    }

    public function payBill(Request $request, Order $order)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');
        abort_unless($order->shop_id === $shop->id, 404);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'in:'.implode(',', Payment::METHODS)],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        $this->orders->collectAnytime(
            $order,
            $request->user(),
            (float) $data['amount'],
            $data['method'],
            $data['reference'] ?? null,
        );

        $baki = $order->fresh(['invoice', 'advanceInvoice'])->collectibleBalance();
        $msg = 'Payment submitted. Office will confirm it on your statement.';
        if ($baki > 0.009) {
            $msg .= ' Baki left: ৳ '.number_format($baki, 2).'.';
        }

        return redirect()->route('portal.orders.show', $order)->with('success', $msg);
    }

    public function profile(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');
        $shop->load(['territory', 'priceGroup', 'assignedSalesman']);

        return view('portal.profile', compact('shop'));
    }

    public function statement(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');
        $statement = $this->ledger->statement($shop);
        $statement['receipts'] = Payment::query()
            ->with('creator')
            ->where('shop_id', $shop->id)
            ->whereIn('status', [Payment::STATUS_VERIFIED, Payment::STATUS_PENDING])
            ->latest('paid_at')
            ->latest('id')
            ->limit(80)
            ->get();

        return view('portal.statement', $statement);
    }
}
