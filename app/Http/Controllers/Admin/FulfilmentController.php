<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderFulfilment;
use App\Models\User;
use App\Services\FulfilmentService;
use App\Support\DeliveryMen;
use App\Support\DemoData;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

        $drivers = DeliveryMen::available()
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

        $allowedIds = DeliveryMen::available()->pluck('id')->all();

        $data = $request->validate([
            'assigned_to' => ['required', 'integer', Rule::in($allowedIds)],
            'tracking_ref' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'assigned_to.required' => 'Select a delivery man before dispatch.',
            'assigned_to.in' => 'Select an available delivery man.',
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

        $allowedIds = DeliveryMen::available()->pluck('id')->all();

        $data = $request->validate([
            'assigned_to' => ['nullable', 'integer', Rule::in($allowedIds)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'collected_now' => ['nullable', 'numeric', 'min:0'],
            'method' => ['nullable', 'in:cash,bank_transfer,cheque,mobile_banking'],
            'reference' => ['nullable', 'string', 'max:120'],
        ], [
            'assigned_to.in' => 'Select an available delivery man.',
        ]);
        $collected = round((float) ($data['collected_now'] ?? 0), 2);
        $note = $data['notes'] ?? null;
        $assigneeId = isset($data['assigned_to']) ? (int) $data['assigned_to'] : null;

        if (! $fulfilment->delivery) {
            $order = $fulfilment->order;
            $this->fulfilment->deliverNow(
                $order,
                $request->user(),
                $note,
                $assigneeId,
            );

            return $this->actionResponse(
                $request,
                $fulfilment->fresh(['order.shop', 'order.items', 'warehouse', 'delivery.assignee']),
                'Order delivered.',
            );
        }

        if ($fulfilment->delivery && $fulfilment->delivery->status !== \App\Models\Delivery::STATUS_DELIVERED) {
            if (! $fulfilment->delivery->assigned_to) {
                if (! $assigneeId) {
                    return back()->withErrors(['assigned_to' => 'Select a delivery man before marking delivered.']);
                }
                $this->fulfilment->assignDeliveryMan($fulfilment->delivery, $assigneeId, $request->user());
            }
            $this->fulfilment->markDelivered(
                $fulfilment->delivery->fresh(),
                $request->user(),
                $note,
                $collected,
                $data['method'] ?? \App\Models\Payment::METHOD_CASH,
                $data['reference'] ?? null,
            );
        } else {
            $this->fulfilment->deliverNow($fulfilment->order, $request->user(), $note, $assigneeId);
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
            'status_label' => self::STAGES[$row->status] ?? ucfirst(str_replace('_', ' ', (string) $row->status)),
            'position' => $position,
            'item_count' => (int) ($row->order?->item_count ?? $row->order?->items?->count() ?? 0),
            'total' => DemoData::taka($row->order?->total ?? 0),
            'picker' => $row->picker?->name,
            'updated' => $row->updated_at?->format('d M H:i'),
            'next_action' => $row->status === OrderFulfilment::STATUS_DELIVERED ? 'View' : 'Open',
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

        $status = $fulfilment->status;
        $delivered = $status === OrderFulfilment::STATUS_DELIVERED;
        $stageOrder = array_keys(self::STAGES);
        $track = collect($stageOrder)->map(function (string $key, int $idx) use ($fulfilment, $position, $delivered) {
            $at = match ($key) {
                OrderFulfilment::STATUS_PICKING => $fulfilment->picking_started_at?->format('d M H:i'),
                OrderFulfilment::STATUS_PICKED => $fulfilment->picked_at?->format('d M H:i'),
                OrderFulfilment::STATUS_PACKED => $fulfilment->packed_at?->format('d M H:i'),
                OrderFulfilment::STATUS_DISPATCHED => $fulfilment->dispatched_at?->format('d M H:i'),
                OrderFulfilment::STATUS_DELIVERED => $fulfilment->delivered_at?->format('d M H:i'),
                default => null,
            };

            return [
                'key' => $key,
                'label' => self::STAGES[$key],
                'done' => $delivered || ($position !== false && $idx < $position),
                'current' => ! $delivered && $position === $idx,
                'at' => $at,
            ];
        })->values()->all();

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
            'status' => $status,
            'status_label' => self::STAGES[$status] ?? ucfirst(str_replace('_', ' ', (string) $status)),
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
            'can_start_pick' => (bool) ($user?->can('fulfilment.pick') && $status === OrderFulfilment::STATUS_AWAITING_PICK),
            'can_complete_pick' => (bool) ($user?->can('fulfilment.pick') && $status === OrderFulfilment::STATUS_PICKING),
            'can_pack' => (bool) ($user?->can('fulfilment.pack') && $status === OrderFulfilment::STATUS_PICKED),
            'can_dispatch' => (bool) ($user?->can('fulfilment.dispatch') && $status === OrderFulfilment::STATUS_PACKED),
            'can_deliver_now' => $canDeliver && ! $delivered && ! in_array($status, [
                OrderFulfilment::STATUS_AWAITING_PICK,
                OrderFulfilment::STATUS_PICKING,
                OrderFulfilment::STATUS_PICKED,
                OrderFulfilment::STATUS_PACKED,
            ], true),
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
