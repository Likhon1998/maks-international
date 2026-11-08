<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\Payment;
use App\Services\FulfilmentService;
use App\Support\DeliveryMen;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeliveryController extends Controller
{
    public function __construct(private FulfilmentService $fulfilment) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->can('deliveries.view'), 403);

        $deliveries = Delivery::query()
            ->with(['order', 'shop', 'warehouse', 'assignee'])
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->assigned_to, fn ($q, $id) => $q->where('assigned_to', $id))
            ->when($request->unassigned === '1', fn ($q) => $q->whereNull('assigned_to'))
            ->latest('dispatched_at')
            ->paginate(20)
            ->withQueryString();

        $deliveryMen = DeliveryMen::available();

        return view('admin.deliveries.index', compact('deliveries', 'deliveryMen'));
    }

    public function men(Request $request)
    {
        abort_unless($request->user()->can('deliveries.view'), 403);

        $roster = DeliveryMen::rosterWithLoad();

        return view('admin.deliveries.men', compact('roster'));
    }

    public function show(Request $request, Delivery $delivery)
    {
        abort_unless($request->user()->can('deliveries.view'), 403);

        $delivery->load(['order.items', 'order.advanceInvoice', 'order.invoice', 'shop', 'warehouse', 'assignee', 'fulfilment']);
        $deliveryMen = DeliveryMen::available();

        return view('admin.deliveries.show', compact('delivery', 'deliveryMen'));
    }

    public function challan(Request $request, Delivery $delivery)
    {
        abort_unless($request->user()->can('deliveries.view'), 403);

        $delivery->load(['order.items', 'shop', 'warehouse', 'assignee']);

        $pdf = Pdf::loadView('admin.deliveries.challan', [
            'delivery' => $delivery,
            'generatedAt' => now(),
        ]);

        return $pdf->download($delivery->number.'-challan.pdf');
    }

    public function assign(Request $request, Delivery $delivery)
    {
        abort_unless($request->user()->can('deliveries.assign'), 403);

        $allowedIds = DeliveryMen::available()->pluck('id')->all();

        $data = $request->validate([
            'assigned_to' => ['required', 'integer', Rule::in($allowedIds)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'mark_out' => ['nullable', 'boolean'],
        ], [
            'assigned_to.required' => 'Select a delivery man.',
            'assigned_to.in' => 'Select an available delivery man.',
        ]);

        $delivery = $this->fulfilment->assignDeliveryMan(
            $delivery,
            (int) $data['assigned_to'],
            $request->user(),
            $data['notes'] ?? null,
        );

        if ($request->boolean('mark_out')) {
            $delivery = $this->fulfilment->markOutForDelivery($delivery, $request->user());
        }

        $name = $delivery->assignee?->name ?: 'delivery man';

        return back()->with('success', "Assigned to {$name}.");
    }

    public function markOutForDelivery(Request $request, Delivery $delivery)
    {
        abort_unless($request->user()->can('deliveries.update_status'), 403);

        $data = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);
        $this->fulfilment->markOutForDelivery($delivery, $request->user(), $data['notes'] ?? null);

        return back()->with('success', 'Marked out for delivery.');
    }

    public function markDelivered(Request $request, Delivery $delivery)
    {
        abort_unless($request->user()->can('deliveries.update_status'), 403);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
            'collected_now' => ['nullable', 'numeric', 'min:0'],
            'method' => ['nullable', 'in:'.implode(',', Payment::METHODS)],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        $collected = round((float) ($data['collected_now'] ?? 0), 2);
        $delivery = $this->fulfilment->markDelivered(
            $delivery,
            $request->user(),
            $data['notes'] ?? null,
            $collected,
            $data['method'] ?? Payment::METHOD_CASH,
            $data['reference'] ?? null,
        );

        $baki = (float) ($delivery->order?->fresh(['invoice', 'advanceInvoice'])->collectibleBalance() ?? 0);
        $message = 'Goods marked delivered.';
        if ($collected > 0.009) {
            $message .= ' Owner paid ৳ '.number_format($collected, 2).' at the shop.';
        }
        $message .= $baki > 0.009
            ? ' Baki ৳ '.number_format($baki, 2).' left.'
            : ' Bill cleared.';

        return back()->with('success', $message);
    }

    public function markFailed(Request $request, Delivery $delivery)
    {
        abort_unless($request->user()->can('deliveries.update_status'), 403);

        $data = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);
        $this->fulfilment->markFailed($delivery, $request->user(), $data['notes'] ?? null);

        return back()->with('success', 'Marked as failed. The van can retry from this page.');
    }

    public function retry(Request $request, Delivery $delivery)
    {
        abort_unless($request->user()->can('deliveries.update_status'), 403);

        $data = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);
        $this->fulfilment->retryDelivery($delivery, $request->user(), $data['notes'] ?? null);

        return back()->with('success', $delivery->fresh()->assigned_to
            ? 'Queued for retry — mark out for delivery when the rider leaves.'
            : 'Queued for retry — assign a delivery man first.');
    }
}
