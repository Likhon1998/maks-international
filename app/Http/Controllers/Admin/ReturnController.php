<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\Shop;
use App\Services\ReturnService;
use Illuminate\Http\Request;

class ReturnController extends Controller
{
    public function __construct(private ReturnService $returns) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->can('returns.view'), 403);

        $status = $request->get('status');
        $counts = [
            'all' => ProductReturn::query()->whereHas('shop', fn ($q) => $q->visibleTo($request->user()))->count(),
            'pending' => ProductReturn::query()->whereHas('shop', fn ($q) => $q->visibleTo($request->user()))->where('status', ProductReturn::STATUS_PENDING)->count(),
            'approved' => ProductReturn::query()->whereHas('shop', fn ($q) => $q->visibleTo($request->user()))->where('status', ProductReturn::STATUS_APPROVED)->count(),
            'restocked' => ProductReturn::query()->whereHas('shop', fn ($q) => $q->visibleTo($request->user()))->where('status', ProductReturn::STATUS_RESTOCKED)->count(),
            'rejected' => ProductReturn::query()->whereHas('shop', fn ($q) => $q->visibleTo($request->user()))->where('status', ProductReturn::STATUS_REJECTED)->count(),
        ];

        $returns = ProductReturn::query()
            ->whereHas('shop', fn ($q) => $q->visibleTo($request->user()))
            ->with(['shop', 'order.salesman', 'creator', 'items'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.returns.index', compact('returns', 'counts', 'status'));
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->can('returns.create'), 403);

        return view('admin.returns.create', [
            'shops' => Shop::query()->visibleTo($request->user())->whereIn('status', [Shop::STATUS_ACTIVE, Shop::STATUS_ON_HOLD])->orderBy('name')->get(),
            'orders' => Order::query()->visibleTo($request->user())->with('shop:id,name')->where('status', Order::STATUS_DELIVERED)->latest()->limit(100)->get(),
            'products' => Product::query()->where('status', Product::STATUS_ACTIVE)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('returns.create'), 403);

        $data = $request->validate([
            'shop_id' => ['required', 'exists:shops,id'],
            'order_id' => ['required', 'exists:orders,id'],
            'reason_type' => ['required', 'in:warranty,defect,wrong_item,not_accepted,other'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'restock' => ['nullable', 'boolean'],
            'items' => ['required', 'array'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['nullable', 'integer', 'min:0'],
        ], [
            'order_id.required' => 'Pick the delivered order these goods came from.',
        ]);
        abort_unless(Shop::query()->findOrFail($data['shop_id'])->isAccessibleBy($request->user()), 403);

        $return = $this->returns->create($data + [
            'restock' => $request->boolean('restock', true),
        ], $data['items'], $request->user());

        return redirect()->route('returns.show', $return)->with('success', 'Return submitted for review. Stock is not added to inventory until approved and restocked.');
    }

    public function show(Request $request, ProductReturn $productReturn)
    {
        abort_unless($request->user()->can('returns.view'), 403);
        abort_unless($productReturn->shop?->isAccessibleBy($request->user()), 403);

        $productReturn->load(['shop', 'order.salesman', 'invoice', 'items.product', 'approver', 'restocker', 'creator', 'delivery']);

        return view('admin.returns.show', ['return' => $productReturn]);
    }

    public function approve(Request $request, ProductReturn $productReturn)
    {
        abort_unless($request->user()->can('returns.approve'), 403);
        abort_unless($productReturn->shop?->isAccessibleBy($request->user()), 403);

        $data = $request->validate([
            'resolution_notes' => ['nullable', 'string', 'max:2000'],
            'restock_now' => ['nullable', 'boolean'],
        ]);

        $restockNow = $request->boolean('restock_now', true);
        $this->returns->approve($productReturn, $request->user(), $data['resolution_notes'] ?? null, $restockNow);

        $message = $restockNow
            ? 'Return approved and quantities added to inventory.'
            : 'Return approved. Credit issued — add to inventory when ready.';

        return back()->with('success', $message);
    }

    public function restock(Request $request, ProductReturn $productReturn)
    {
        abort_unless($request->user()->can('returns.approve'), 403);
        abort_unless($productReturn->shop?->isAccessibleBy($request->user()), 403);

        $data = $request->validate(['resolution_notes' => ['nullable', 'string', 'max:2000']]);
        $this->returns->addToInventory($productReturn, $request->user(), $data['resolution_notes'] ?? null);

        return back()->with('success', 'Returned products added to main inventory.');
    }

    public function reject(Request $request, ProductReturn $productReturn)
    {
        abort_unless($request->user()->can('returns.reject'), 403);
        abort_unless($productReturn->shop?->isAccessibleBy($request->user()), 403);

        $data = $request->validate(['resolution_notes' => ['required', 'string', 'max:2000']]);
        $this->returns->reject($productReturn, $data['resolution_notes'], $request->user());

        return back()->with('success', 'Return rejected. Inventory was not changed.');
    }
}
