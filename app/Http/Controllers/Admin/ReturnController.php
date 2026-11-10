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

        $status = (string) $request->get('status', '');
        $kind = in_array($request->get('kind'), ['returns', 'warranty'], true) ? $request->get('kind') : '';
        $search = trim((string) $request->get('q', ''));

        $base = ProductReturn::query()->whereHas('shop', fn ($q) => $q->visibleTo($request->user()));
        $counts = [
            'all' => (clone $base)->count(),
            'pending' => (clone $base)->where('status', ProductReturn::STATUS_PENDING)->count(),
            'approved' => (clone $base)->where('status', ProductReturn::STATUS_APPROVED)->count(),
            'restocked' => (clone $base)->where('status', ProductReturn::STATUS_RESTOCKED)->count(),
            'rejected' => (clone $base)->where('status', ProductReturn::STATUS_REJECTED)->count(),
            'repaired' => (clone $base)->where('repair_status', ProductReturn::REPAIR_DONE)->count(),
            'replaced' => (clone $base)->where('replaced', true)->count(),
            'refunded' => (clone $base)->where(fn ($q) => $q->where('credit_issued', true)->orWhere('refund_amount', '>', 0))->count(),
            'returns' => (clone $base)->where('reason_type', '!=', ProductReturn::REASON_WARRANTY)->count(),
            'warranty' => (clone $base)->where('reason_type', ProductReturn::REASON_WARRANTY)->count(),
        ];

        $returns = ProductReturn::query()
            ->whereHas('shop', fn ($q) => $q->visibleTo($request->user()))
            ->with(['shop', 'order.salesman', 'invoice', 'creator', 'items'])
            ->when($kind === 'warranty', fn ($q) => $q->where('reason_type', ProductReturn::REASON_WARRANTY))
            ->when($kind === 'returns', fn ($q) => $q->where('reason_type', '!=', ProductReturn::REASON_WARRANTY))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('number', 'like', '%'.$search.'%')
                        ->orWhereHas('shop', fn ($s) => $s->where('name', 'like', '%'.$search.'%')->orWhere('owner_name', 'like', '%'.$search.'%'))
                        ->orWhereHas('order', fn ($o) => $o->where('number', 'like', '%'.$search.'%'))
                        ->orWhereHas('invoice', fn ($i) => $i->where('number', 'like', '%'.$search.'%'))
                        ->orWhereHas('items', fn ($it) => $it->where('product_name', 'like', '%'.$search.'%')->orWhere('product_sku', 'like', '%'.$search.'%'));
                });
            })
            ->when($status !== '', function ($q) use ($status) {
                match ($status) {
                    'pending' => $q->where('status', ProductReturn::STATUS_PENDING),
                    'approved' => $q->whereIn('status', [ProductReturn::STATUS_APPROVED, ProductReturn::STATUS_RESTOCKED]),
                    'restocked' => $q->where('status', ProductReturn::STATUS_RESTOCKED),
                    'rejected' => $q->where('status', ProductReturn::STATUS_REJECTED),
                    'repaired' => $q->where('repair_status', ProductReturn::REPAIR_DONE),
                    'replaced' => $q->where('replaced', true),
                    'refunded' => $q->where(fn ($inner) => $inner->where('credit_issued', true)->orWhere('refund_amount', '>', 0)),
                    default => $q->where('status', $status),
                };
            })
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.returns.index', compact('returns', 'counts', 'status', 'kind', 'search'));
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
            'reason_type' => ['required', 'in:warranty,defect,damaged,wrong_item,not_accepted,other'],
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
            'replaced' => ['nullable', 'boolean'],
            'repair_status' => ['nullable', 'in:none,pending,in_repair,repaired'],
            'inventory_condition' => ['nullable', 'in:good,damaged,repair'],
        ]);

        $restockNow = $request->boolean('restock_now', true);
        $this->returns->approve($productReturn, $request->user(), $data['resolution_notes'] ?? null, $restockNow, [
            'replaced' => $request->boolean('replaced'),
            'repair_status' => $data['repair_status'] ?? ProductReturn::REPAIR_NONE,
            'inventory_condition' => $data['inventory_condition'] ?? null,
        ]);

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
