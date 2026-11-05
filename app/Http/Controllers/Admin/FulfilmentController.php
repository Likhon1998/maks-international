<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderFulfilment;
use App\Models\User;
use App\Services\FulfilmentService;
use App\Support\DemoData;
use Illuminate\Http\Request;

class FulfilmentController extends Controller
{
    private const STAGES = [
        OrderFulfilment::STATUS_AWAITING_PICK => 'Awaiting pick',
        OrderFulfilment::STATUS_PICKING => 'Picking',
        OrderFulfilment::STATUS_PICKED => 'Picked',
        OrderFulfilment::STATUS_PACKED => 'Packed',
        OrderFulfilment::STATUS_DISPATCHED => 'Dispatched',
        OrderFulfilment::STATUS_DELIVERED => 'Delivered',
    ];

    public function __construct(private FulfilmentService $fulfilment) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->can('fulfilment.view'), 403);

        $user = $request->user();
        $queue = OrderFulfilment::query()
            ->with(['order.shop', 'order.items', 'warehouse', 'picker', 'packer', 'dispatcher', 'delivery.assignee'])
            ->latest()
            ->limit(300)
            ->get();

        $rows = $queue->map(fn (OrderFulfilment $row) => $this->boardRow($row))->values();
        $details = $queue->mapWithKeys(
            fn (OrderFulfilment $row) => [$row->id => $this->detailPayload($row, $user)]
        );
        $counts = $this->stageCounts();

        $drivers = User::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('portal', User::PORTAL_SALESMAN)
                    ->orWhere(function ($q2) {
                        $q2->where('portal', User::PORTAL_ADMIN)
                            ->whereHas('roles', fn ($r) => $r->whereIn('name', [
                                'Delivery Staff', 'Delivery Manager', 'Warehouse Staff', 'Warehouse Manager', 'Super Admin', 'Admin',
                            ]));
                    });
            })
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->values();

        $permissions = [
            'pick' => $user->can('fulfilment.pick'),
            'pack' => $user->can('fulfilment.pack'),
            'dispatch' => $user->can('fulfilment.dispatch'),
            'deliver' => $user->can('deliveries.update_status'),
        ];

        $initialStatus = (string) $request->get('status', '');
        $initialId = (int) $request->get('open', 0);

        return view('admin.fulfilment.index', [
            'stages' => self::STAGES,
            'counts' => $counts,
            'rows' => $rows,
            'details' => $details,
            'drivers' => $drivers,
            'permissions' => $permissions,
            'initialStatus' => $initialStatus,
            'initialId' => $initialId,
            'csrf' => csrf_token(),
        ]);
    }

    public function workspace(Request $request, OrderFulfilment $fulfilment)
    {
        abort_unless($request->user()->can('fulfilment.view'), 403);

        $fulfilment->load(['order.items.product', 'order.shop', 'warehouse', 'picker', 'packer', 'dispatcher', 'delivery.assignee']);

        return response()->json([
            'row' => $this->boardRow($fulfilment),
            'detail' => $this->detailPayload($fulfilment, $request->user()),
            'counts' => $this->stageCounts(),
        ]);
    }

    public function show(Request $request, OrderFulfilment $fulfilment)
    {
        return redirect()->route('fulfilment.index', ['open' => $fulfilment->id, 'status' => $fulfilment->status]);
    }

    public function startPick(Request $request, OrderFulfilment $fulfilment)
    {
        abort_unless($request->user()->can('fulfilment.pick'), 403);
        $this->fulfilment->startPick($fulfilment, $request->user());

        return $this->actionResponse($request, $fulfilment->fresh(), 'Picking started.');
    }

    public function completePick(Request $request, OrderFulfilment $fulfilment)
    {
        abort_unless($request->user()->can('fulfilment.pick'), 403);

        $data = $request->validate(['warehouse_notes' => ['nullable', 'string', 'max:1000']]);
        $this->fulfilment->completePick($fulfilment, $request->user(), $data['warehouse_notes'] ?? null);

        return $this->actionResponse($request, $fulfilment->fresh(), 'Pick completed — stock deducted.');
    }

    public function pack(Request $request, OrderFulfilment $fulfilment)
    {
        abort_unless($request->user()->can('fulfilment.pack'), 403);

        $data = $request->validate(['warehouse_notes' => ['nullable', 'string', 'max:1000']]);
        $this->fulfilment->pack($fulfilment, $request->user(), $data['warehouse_notes'] ?? null);

        return $this->actionResponse($request, $fulfilment->fresh(), 'Order packed and ready to dispatch.');
    }

    public function dispatch(Request $request, OrderFulfilment $fulfilment)
    {
        abort_unless($request->user()->can('fulfilment.dispatch'), 403);

        $data = $request->validate([
            'assigned_to' => ['required', 'exists:users,id'],
            'tracking_ref' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'assigned_to.required' => 'Select a salesman to deliver this order.',
        ]);

        $delivery = $this->fulfilment->dispatch($fulfilment, $request->user(), $data);

        return $this->actionResponse(
            $request,
            $fulfilment->fresh(['order.shop', 'order.items', 'warehouse', 'picker', 'packer', 'dispatcher', 'delivery.assignee']),
            'Dispatched as '.$delivery->number.'.',
        );
    }

    public function markDelivered(Request $request, OrderFulfilment $fulfilment)
    {
        abort_unless(
            $request->user()->can('deliveries.update_status') || $request->user()->can('orders.approve'),
            403
        );

        $fulfilment->loadMissing(['delivery', 'order']);

        $data = $request->validate([
            'assigned_to' => ['nullable', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'collected_now' => ['nullable', 'numeric', 'min:0'],
            'method' => ['nullable', 'in:cash,bank_transfer,cheque,mobile_banking'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);
        $collected = round((float) ($data['collected_now'] ?? 0), 2);
        $note = $data['notes'] ?? null;

        if (! $fulfilment->delivery) {
            $order = $fulfilment->order;
            $this->fulfilment->deliverNow(
                $order,
                $request->user(),
                $note,
                isset($data['assigned_to']) ? (int) $data['assigned_to'] : null,
            );

            return $this->actionResponse(
                $request,
                $fulfilment->fresh(['order.shop', 'order.items', 'warehouse', 'delivery.assignee']),
                'Order delivered.',
            );
        }

        if ($fulfilment->delivery && $fulfilment->delivery->status !== \App\Models\Delivery::STATUS_DELIVERED) {
            if (! empty($data['assigned_to']) && ! $fulfilment->delivery->assigned_to) {
                $fulfilment->delivery->update(['assigned_to' => (int) $data['assigned_to']]);
            }
            $this->fulfilment->markDelivered(
                $fulfilment->delivery,
                $request->user(),
                $note,
                $collected,
                $data['method'] ?? \App\Models\Payment::METHOD_CASH,
                $data['reference'] ?? null,
            );
        } else {
            $this->fulfilment->deliverNow($fulfilment->order, $request->user(), $note);
        }

        $message = 'Marked delivered.';
        if ($collected > 0.009) {
            $message = 'Marked delivered. Owner paid ৳ '.number_format($collected, 2).' at the shop.';
        }

        return $this->actionResponse(
            $request,
            $fulfilment->fresh(['order.shop', 'order.items', 'warehouse', 'picker', 'packer', 'dispatcher', 'delivery.assignee']),
            $message,
        );
    }

    private function actionResponse(Request $request, OrderFulfilment $fulfilment, string $message)
    {
        $fulfilment->load(['order.shop', 'order.items.product', 'warehouse', 'picker', 'packer', 'dispatcher', 'delivery.assignee']);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'row' => $this->boardRow($fulfilment),
                'detail' => $this->detailPayload($fulfilment, $request->user()),
                'counts' => $this->stageCounts(),
            ]);
        }

        return redirect()
            ->route('fulfilment.index', ['open' => $fulfilment->id, 'status' => $fulfilment->status])
            ->with('success', $message);
    }

    /**
     * @return array<string, int>
     */
    private function stageCounts(): array
    {
        $raw = OrderFulfilment::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return collect(self::STAGES)->mapWithKeys(
            fn ($label, $key) => [$key => (int) ($raw[$key] ?? 0)]
        )->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function boardRow(OrderFulfilment $row): array
    {
        $stageKeys = array_keys(self::STAGES);
        $position = array_search($row->status, $stageKeys, true);
        if ($position === false) {
            $position = 0;
        }

        return [
            'id' => $row->id,
            'order_number' => $row->order?->number,
            'shop' => $row->order?->shop?->name,
            'warehouse' => $row->warehouse?->code ?: $row->warehouse?->name,
            'status' => $row->status,
            'status_label' => $row->status === OrderFulfilment::STATUS_DELIVERED ? 'Delivered' : 'Approved',
            'position' => $position,
            'item_count' => (int) ($row->order?->item_count ?? $row->order?->items?->count() ?? 0),
            'total' => DemoData::taka($row->order?->total ?? 0),
            'picker' => $row->picker?->name,
            'updated' => $row->updated_at?->format('d M H:i'),
            'next_action' => $row->status === OrderFulfilment::STATUS_DELIVERED ? 'View' : 'Deliver',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(OrderFulfilment $fulfilment, ?User $user = null): array
    {
        $stageKeys = array_keys(self::STAGES);
        $position = array_search($fulfilment->status, $stageKeys, true);
        if ($position === false) {
            $position = 0;
        }

        $delivered = $fulfilment->status === OrderFulfilment::STATUS_DELIVERED;
        $track = [
            [
                'key' => 'ready',
                'label' => 'Approved',
                'done' => $delivered,
                'current' => ! $delivered,
                'at' => null,
            ],
            [
                'key' => 'delivered',
                'label' => 'Delivered',
                'done' => $delivered,
                'current' => $delivered,
                'at' => $fulfilment->delivered_at?->format('d M H:i'),
            ],
        ];

        $canDeliver = ($user?->can('deliveries.update_status') || $user?->can('orders.approve'))
            && $fulfilment->order
            && $fulfilment->order->canDeliver();

        return [
            'id' => $fulfilment->id,
            'order_id' => $fulfilment->order_id,
            'order_number' => $fulfilment->order?->number,
            'shop' => $fulfilment->order?->shop?->name,
            'shop_city' => $fulfilment->order?->shop?->city,
            'warehouse' => $fulfilment->warehouse?->name,
            'warehouse_code' => $fulfilment->warehouse?->code,
            'status' => $fulfilment->status,
            'status_label' => $delivered ? 'Delivered' : 'Approved',
            'position' => $position,
            'total' => DemoData::taka($fulfilment->order?->total ?? 0),
            'notes' => $fulfilment->warehouse_notes,
            'picker' => $fulfilment->picker?->name,
            'packer' => $fulfilment->packer?->name,
            'dispatcher' => $fulfilment->dispatcher?->name,
            'delivery_number' => $fulfilment->delivery?->number,
            'delivery_id' => $fulfilment->delivery?->id,
            'assignee' => $fulfilment->delivery?->assignee?->name,
            'tracking_ref' => $fulfilment->delivery?->tracking_ref,
            'can_start_pick' => false,
            'can_complete_pick' => false,
            'can_pack' => false,
            'can_dispatch' => false,
            'can_deliver_now' => $canDeliver,
            'delivery_url' => $fulfilment->delivery ? route('deliveries.show', $fulfilment->delivery, false) : null,
            'urls' => [
                'start_pick' => route('fulfilment.start-pick', $fulfilment, false),
                'complete_pick' => route('fulfilment.complete-pick', $fulfilment, false),
                'pack' => route('fulfilment.pack', $fulfilment, false),
                'dispatch' => route('fulfilment.dispatch', $fulfilment, false),
                'deliver' => route('fulfilment.deliver', $fulfilment, false),
                'order' => route('orders.show', $fulfilment->order_id, false),
            ],
            'track' => $track,
            'items' => ($fulfilment->order?->items ?? collect())->map(fn ($item) => [
                'sku' => $item->product_sku,
                'name' => $item->product_name,
                'qty' => $item->quantity,
                'reserved' => (int) $item->reserved_quantity,
                'line' => DemoData::taka($item->line_total),
            ])->values(),
        ];
    }
}
