<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\OrderService;
use App\Support\DeliveryMen;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function __construct(private OrderService $orders) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Order::class);

        $pendingCount = Order::query()
            ->visibleTo($request->user())
            ->whereIn('status', [Order::STATUS_PENDING_AUDIT, Order::STATUS_AWAITING_ADVANCE])
            ->count();

        $orders = Order::query()
            ->visibleTo($request->user())
            ->with(['shop', 'salesman', 'auditor', 'items.product', 'advanceInvoice'])
            ->latest('submitted_at')
            ->limit(500)
            ->get();

        $rows = $orders->map(fn (Order $order) => [
            'id' => $order->id,
            'number' => $order->number,
            'shop' => $order->shop?->name ?: '',
            'shop_code' => $order->shop?->code ?: '',
            'shopkeeper' => $order->shop?->owner_name ?: $order->shop?->name ?: '',
            'source' => $order->source,
            'source_label' => ucfirst(str_replace('_', ' ', $order->source)),
            'salesman' => $order->salesman?->name ?: '—',
            'item_count' => $order->item_count,
            'total' => \App\Support\DemoData::taka($order->total),
            'price_changed' => $order->items->contains(fn ($item) => $item->wasPriceChanged()),
            'submitted_at' => $order->submitted_at?->format('d M Y H:i') ?: '—',
            'status' => $order->status,
            'status_label' => $order->statusLabel(),
            'status_badge' => $this->statusBadgeClass($order),
            'advance_paid' => $order->isAwaitingAdvance() && $order->isAdvancePaid(),
            'show_url' => route('orders.show', $order, false),
        ])->values();

        $initialFilters = [
            'search' => (string) $request->get('search', ''),
            'status' => $request->boolean('audit_queue')
                ? Order::STATUS_PENDING_AUDIT
                : (string) $request->get('status', ''),
            'source' => (string) $request->get('source', ''),
        ];

        return view('admin.orders.index', compact('rows', 'pendingCount', 'initialFilters'));
    }

    public function create(Request $request)
    {
        $this->authorize('create', Order::class);

        return view('admin.orders.create', [
            'shops' => Shop::query()
                ->visibleTo($request->user())
                ->with(['priceGroup', 'assignedSalesman'])
                ->where('status', Shop::STATUS_ACTIVE)
                ->orderBy('name')
                ->get(),
            'products' => Product::query()
                ->with('prices')
                ->where('status', Product::STATUS_ACTIVE)
                ->where('is_published', true)
                ->orderBy('name')
                ->get(),
            'salesmen' => User::query()
                ->where('portal', User::PORTAL_SALESMAN)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Order::class);

        $data = $request->validate([
            'shop_id' => ['required', 'exists:shops,id'],
            'salesman_id' => ['nullable', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'discount_total' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'items' => ['required', 'array'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['nullable', 'integer', 'min:0'],
        ]);

        $shop = Shop::query()->findOrFail($data['shop_id']);
        abort_unless($shop->isAccessibleBy($request->user()), 403, 'This shop is outside your access scope.');

        if ($shop->status !== Shop::STATUS_ACTIVE) {
            return back()
                ->withInput()
                ->withErrors(['shop_id' => 'Orders can only be placed for active shops.']);
        }

        $order = $this->orders->createFromAdmin(
            $request->user(),
            $shop,
            $data['items'],
            $data['notes'] ?? null,
            $data['salesman_id'] ?? null,
            (float) ($data['discount_total'] ?? 0),
        );

        return redirect()->route('orders.show', $order)
            ->with('success', 'Order '.$order->number.' created for '.$shop->name.' and submitted for Super Admin audit.');
    }

    public function discount(Request $request, Order $order)
    {
        $this->authorize('update', $order);

        $data = $request->validate([
            'discount_total' => ['required', 'numeric', 'min:0'],
        ]);

        $this->orders->applyDiscount($order, (float) $data['discount_total'], $request->user());

        return back()->with('success', 'Order total reduced. Discount ৳ '.number_format((float) $data['discount_total'], 2).'.');
    }

    public function show(Order $order)
    {
        $this->authorize('view', $order);

        $user = request()->user();
        $payload = $this->previewPayload($order, $user);
        $snapshot = $this->orders->auditSnapshot($order);
        $purchase = $payload['purchase'];

        $order->loadMissing([
            'shop.priceGroup', 'salesman', 'visit', 'items.product',
            'creator', 'auditor', 'canceller', 'statusHistories.user',
            'warehouse', 'fulfilment.delivery.assignee', 'delivery.assignee',
            'invoice.items', 'invoice.payments', 'invoice.shop', 'invoice.creator', 'invoice.order',
            'advanceInvoice.items', 'advanceInvoice.payments', 'advanceInvoice.shop',
            'advanceInvoice.creator', 'advanceInvoice.order',
        ]);

        $invoicePreviews = collect();
        if ($order->advanceInvoice) {
            $order->advanceInvoice->setRelation('order', $order);
            $invoicePreviews->push([
                'title' => 'Advance invoice',
                'invoice' => $order->advanceInvoice,
                'kind' => 'Advance Invoice',
            ]);
        }
        if ($order->invoice && (int) $order->invoice_id !== (int) $order->advance_invoice_id) {
            $order->invoice->setRelation('order', $order);
            $invoicePreviews->push([
                'title' => 'Sales invoice',
                'invoice' => $order->invoice,
                'kind' => 'Sales Invoice',
            ]);
        }

        return view('admin.orders.show', [
            'order' => $order,
            'snapshot' => $snapshot,
            'purchase' => $purchase,
            'payload' => $payload,
            'invoicePreviews' => $invoicePreviews,
            'deliveryMen' => DeliveryMen::available(),
        ]);
    }

    public function preview(Order $order)
    {
        $this->authorize('view', $order);

        $order->load(['shop', 'salesman', 'items.product', 'auditor']);

        return response()->json($this->previewPayload($order, request()->user()));
    }

    public function approve(Request $request, Order $order)
    {
        $this->authorize('approve', $order);

        $data = $request->validate([
            'audit_notes' => ['nullable', 'string', 'max:2000'],
            'credit_override' => ['nullable', 'boolean'],
            'return_url' => ['nullable', 'string', 'max:500'],
        ]);

        $this->orders->approve(
            $order,
            $request->user(),
            $data['audit_notes'] ?? null,
            $request->boolean('credit_override'),
        );

        $order->refresh();

        return $this->redirectAfterAudit(
            $request,
            $order,
            'Order '.$order->number.' approved — status is now '.$order->statusLabel().'.',
            'approved',
        );
    }

    public function deliver(Request $request, Order $order)
    {
        $this->authorize('view', $order);
        abort_unless(
            $request->user()->can('deliveries.update_status') || $request->user()->can('orders.approve'),
            403
        );

        $order->loadMissing('delivery');
        $allowedIds = DeliveryMen::available()->pluck('id')->all();
        $needsAssignee = ! $order->delivery?->assigned_to;

        $data = $request->validate([
            'assigned_to' => [$needsAssignee ? 'required' : 'nullable', 'integer', Rule::in($allowedIds)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'assigned_to.required' => 'Select a delivery man before marking delivered.',
            'assigned_to.in' => 'Select an available delivery man.',
        ]);

        $assigneeId = isset($data['assigned_to']) ? (int) $data['assigned_to'] : null;
        if ($needsAssignee && ! $assigneeId) {
            throw ValidationException::withMessages([
                'assigned_to' => 'Select a delivery man before marking delivered.',
            ]);
        }

        app(\App\Services\FulfilmentService::class)->deliverNow(
            $order,
            $request->user(),
            $data['notes'] ?? null,
            $assigneeId ?: ($order->delivery?->assigned_to ? (int) $order->delivery->assigned_to : null),
        );

        return redirect()->route('orders.show', $order)
            ->with('success', 'Order '.$order->number.' delivered.');
    }

    public function requestAdvance(Request $request, Order $order)
    {
        $this->authorize('requestAdvance', $order);

        $data = $request->validate([
            'advance_amount' => ['required', 'numeric', 'min:1'],
            'audit_notes' => ['nullable', 'string', 'max:2000'],
            'return_url' => ['nullable', 'string', 'max:500'],
        ]);

        $this->orders->requestAdvance(
            $order,
            $request->user(),
            (float) $data['advance_amount'],
            $data['audit_notes'] ?? null,
        );

        $order->refresh();

        return $this->redirectAfterAudit(
            $request,
            $order,
            'Advance of ৳ '.number_format((float) $data['advance_amount'], 2).' requested for '.$order->number.'. Collect payment, then approve.',
            'advance_requested',
        );
    }

    public function collectAdvance(Request $request, Order $order)
    {
        abort_unless(
            $request->user()->can('orders.approve') && $order->isAccessibleBy($request->user()) && $order->isAwaitingAdvance() && ! $order->isAdvancePaid(),
            403
        );

        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'method' => ['required', 'in:cash,bank_transfer,cheque,mobile_banking'],
            'reference' => ['nullable', 'string', 'max:120'],
            'return_url' => ['nullable', 'string', 'max:500'],
        ]);

        $this->orders->collectAdvance(
            $order,
            $request->user(),
            (float) ($data['amount'] ?? 0),
            $data['method'],
            $data['reference'] ?? null,
        );

        $order->refresh();
        $settlement = $order->settlement();

        $msg = $order->isAdvancePaid()
            ? 'Advance received for '.$order->number
                .' — remaining '.\App\Support\DemoData::taka($settlement['remaining_due'])
                .' still due on final invoice. Status: '.$order->statusLabel().'.'
            : 'Partial advance recorded for '.$order->number
                .'. Advance still due: '.\App\Support\DemoData::taka($order->advanceInvoice?->balance)
                .'. Remaining after full advance: '.\App\Support\DemoData::taka($settlement['remaining_due']).'.';

        return $this->redirectAfterAudit(
            $request,
            $order,
            $msg,
            $order->isAdvancePaid() ? 'advance_paid' : 'advance_partial',
            $order->advance_invoice_id
                ? route('invoices.download', $order->advance_invoice_id)
                : null,
        );
    }

    public function reject(Request $request, Order $order)
    {
        $this->authorize('reject', $order);

        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:2000'],
            'return_url' => ['nullable', 'string', 'max:500'],
        ]);

        $this->orders->reject($order, $request->user(), $data['rejection_reason']);
        $order->refresh();

        return $this->redirectAfterAudit(
            $request,
            $order,
            'Order '.$order->number.' rejected — status is now '.$order->statusLabel().'.',
            'rejected',
        );
    }

    public function cancel(Request $request, Order $order)
    {
        $this->authorize('cancel', $order);

        $data = $request->validate([
            'cancellation_reason' => ['required', 'string', 'max:2000'],
            'return_url' => ['nullable', 'string', 'max:500'],
        ]);

        $wasReserved = $order->stock_reserved || $order->isApproved();
        $this->orders->cancel($order, $request->user(), $data['cancellation_reason']);
        $order->refresh();

        $message = $wasReserved
            ? 'Order '.$order->number.' cancelled and reserved stock released.'
            : 'Order '.$order->number.' cancelled.';

        return $this->redirectAfterAudit($request, $order, $message, 'cancelled');
    }

    public function destroy(Request $request, Order $order)
    {
        $this->authorize('delete', $order);

        $data = $request->validate([
            'return_url' => ['nullable', 'string', 'max:500'],
        ]);

        $number = $order->number;
        $this->orders->deleteBeforeApproval($order, $request->user());

        $return = $data['return_url'] ?? null;
        if (is_string($return) && str_starts_with($return, '/admin/orders')) {
            return redirect()->to($return)
                ->with('success', 'Order '.$number.' deleted before approval.')
                ->with('status_flash', [
                    'order_id' => null,
                    'number' => $number,
                    'status' => 'Deleted',
                    'result' => 'cancelled',
                ]);
        }

        return redirect()->route('orders.index')
            ->with('success', 'Order '.$number.' deleted before approval.');
    }

    /**
     * @return array<string, mixed>
     */
    private function previewPayload(Order $order, ?User $user = null): array
    {
        $order->loadMissing([
            'shop', 'salesman', 'items.product', 'advanceInvoice', 'invoice',
            'statusHistories.user', 'warehouse',
            'fulfilment.delivery.assignee', 'fulfilment.warehouse',
            'fulfilment.picker', 'fulfilment.packer', 'fulfilment.dispatcher',
            'auditor', 'creator', 'canceller',
        ]);
        $snapshot = $this->orders->auditSnapshot($order);
        $approvalStock = $this->orders->approvalStock($order);

        $statusTone = match ($order->status) {
            Order::STATUS_PENDING_AUDIT, Order::STATUS_AWAITING_ADVANCE => 'pending',
            Order::STATUS_APPROVED, Order::STATUS_PICKING, Order::STATUS_PICKED,
            Order::STATUS_PACKED, Order::STATUS_DISPATCHED, Order::STATUS_DELIVERED => 'success',
            Order::STATUS_REJECTED => 'danger',
            Order::STATUS_CANCELLED => 'muted',
            default => 'muted',
        };

        $advancePaid = $order->isAdvancePaid();
        $advanceBalance = $order->advanceInvoice
            ? (float) $order->advanceInvoice->balance
            : (float) ($order->advance_amount ?: 0);

        $settlement = $order->settlement();
        $dossier = $this->purchaseDossier($order);

        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status,
            'status_label' => $order->statusLabel(),
            'status_tone' => $statusTone,
            'source' => ucfirst(str_replace('_', ' ', $order->source)),
            'total' => \App\Support\DemoData::taka($order->total),
            'total_raw' => (float) $order->total,
            'item_count' => $order->item_count,
            'notes' => $order->notes,
            'rejection_reason' => $order->rejection_reason,
            'submitted_at' => $order->submitted_at?->format('d M Y H:i'),
            'shop' => $order->shop?->name,
            'shop_code' => $order->shop?->code,
            'salesman' => $order->salesman?->name,
            'pending_audit' => $order->isPendingAudit() || $order->isAwaitingAdvance(),
            'awaiting_advance' => $order->isAwaitingAdvance(),
            'advance_required' => (bool) $order->advance_required,
            'advance_amount' => $order->advance_amount !== null ? \App\Support\DemoData::taka($order->advance_amount) : null,
            'advance_amount_raw' => (float) ($order->advance_amount ?: 0),
            'advance_paid' => $advancePaid,
            'advance_balance' => \App\Support\DemoData::taka($advanceBalance),
            'advance_balance_raw' => round($advanceBalance, 2),
            'advance_invoice_number' => $order->advanceInvoice?->number,
            'settlement' => [
                'order_total' => \App\Support\DemoData::taka($settlement['order_total']),
                'advance_paid' => \App\Support\DemoData::taka($settlement['advance_paid']),
                'remaining_due' => \App\Support\DemoData::taka($settlement['remaining_due']),
                'remaining_due_raw' => $settlement['remaining_due'],
                'has_advance' => $settlement['has_advance'],
                'advance_cleared' => $settlement['advance_cleared'],
                'fully_settled' => $settlement['fully_settled'],
            ],
            'payment_url' => $order->advance_invoice_id
                ? url('/admin/payments/create?invoice_id='.$order->advance_invoice_id)
                : url('/admin/payments/create'),
            'collect_advance_url' => $order->isAwaitingAdvance() && ! $advancePaid
                ? route('orders.collect-advance', $order, false)
                : null,
            'can_approve' => $user?->can('approve', $order) ?? false,
            'can_request_advance' => $user?->can('requestAdvance', $order) ?? false,
            'can_reject' => $user?->can('reject', $order) ?? false,
            'can_delete' => $user?->can('delete', $order) ?? false,
            'approve_url' => route('orders.approve', $order, false),
            'request_advance_url' => route('orders.request-advance', $order, false),
            'reject_url' => route('orders.reject', $order, false),
            'delete_url' => route('orders.destroy', $order, false),
            'snapshot' => [
                'credit_ok' => $snapshot['credit_ok'],
                'credit_available' => \App\Support\DemoData::taka($snapshot['credit_available']),
                'stock_ok' => $snapshot['stock_ok'],
            ],
            'purchase' => $dossier,
            'payment_history' => $dossier['payments'],
            'items' => $order->items->map(function ($item) use ($approvalStock) {
                $available = $approvalStock[$item->product_id] ?? ($item->available_at_audit ?? 0);
                $ok = $available >= $item->quantity || $item->reserved_quantity > 0;

                return [
                    'sku' => $item->product_sku,
                    'name' => $item->product_name,
                    'qty' => $item->quantity,
                    'available' => (int) $available,
                    'reserved' => (int) $item->reserved_quantity,
                    'original' => \App\Support\DemoData::taka($item->originalUnitPrice()),
                    'unit' => \App\Support\DemoData::taka($item->unit_price),
                    'price_changed' => $item->wasPriceChanged(),
                    'line' => \App\Support\DemoData::taka($item->line_total),
                    'ok' => $ok,
                ];
            })->values(),
        ];
    }

    /**
     * Full purchase dossier for the History panel: timeline, invoices, payments.
     *
     * @return array{summary:array, timeline:list<array>, invoices:list<array>, payments:list<array>, event_count:int}
     */
    private function purchaseDossier(Order $order): array
    {
        $invoiceIds = array_values(array_filter([
            $order->advance_invoice_id,
            $order->invoice_id,
        ]));

        $payments = $invoiceIds === []
            ? collect()
            : Payment::query()
                ->with(['invoice', 'verifier', 'creator'])
                ->whereIn('invoice_id', $invoiceIds)
                ->latest('paid_at')
                ->latest('id')
                ->get();

        $invoices = collect();
        if ($order->advanceInvoice) {
            $invoices->push($this->invoiceCard($order->advanceInvoice, 'Advance invoice'));
        }
        if ($order->invoice && (int) $order->invoice_id !== (int) $order->advance_invoice_id) {
            $invoices->push($this->invoiceCard($order->invoice, 'Sales invoice'));
        }

        $timeline = $this->buildPurchaseTimeline($order, $payments);

        return [
            'summary' => [
                'order' => $order->number,
                'shop' => $order->shop?->name,
                'shop_code' => $order->shop?->code,
                'total' => \App\Support\DemoData::taka($order->total),
                'status' => $order->statusLabel(),
                'source' => ucfirst(str_replace('_', ' ', $order->source)),
                'salesman' => $order->salesman?->name,
                'lines' => (int) $order->item_count,
            ],
            'timeline' => $timeline,
            'invoices' => $invoices->values()->all(),
            'payments' => $payments->map(function (Payment $payment) use ($order) {
                $kind = $payment->invoice_id && (int) $payment->invoice_id === (int) $order->advance_invoice_id
                    ? 'Advance'
                    : 'Invoice';
                $paid = $this->stamp($payment->paid_at);
                $verified = $this->stamp($payment->verified_at);

                return [
                    'id' => $payment->id,
                    'number' => $payment->number,
                    'kind' => $kind,
                    'invoice' => $payment->invoice?->number,
                    'amount' => \App\Support\DemoData::taka($payment->amount),
                    'method' => $payment->methodLabel(),
                    'reference' => $payment->reference,
                    'status' => $payment->statusLabel(),
                    'status_key' => $payment->status,
                    'paid_at' => $paid['full'] ?? '—',
                    'paid_day' => $paid['day'] ?? null,
                    'paid_date' => $paid['date'] ?? null,
                    'paid_time' => $paid['time'] ?? null,
                    'verified_at' => $verified['full'] ?? null,
                    'verified_day' => $verified['day'] ?? null,
                    'verified_date' => $verified['date'] ?? null,
                    'verified_time' => $verified['time'] ?? null,
                    'recorded_by' => $payment->creator?->name,
                    'verified_by' => $payment->verifier?->name,
                    'notes' => $payment->notes,
                ];
            })->values()->all(),
            'event_count' => count($timeline) + $invoices->count() + $payments->count(),
        ];
    }

    /**
     * @return array{title:string, number:string, status:string, status_key:string, total:string, paid:string, balance:string, issued:string|null, issued_day:?string, due:string|null, due_day:?string, notes:?string, link:?string}
     */
    private function invoiceCard(Invoice $invoice, string $title): array
    {
        $issued = $this->stamp($invoice->issued_at);
        $dueFull = null;
        $dueDay = null;
        if ($invoice->due_at) {
            $dueDt = Carbon::parse($invoice->due_at);
            $dueDay = $dueDt->format('l');
            $dueFull = $dueDt->format('l, d M Y');
        }

        return [
            'title' => $title,
            'number' => $invoice->number,
            'status' => $invoice->statusLabel(),
            'status_key' => $invoice->status,
            'total' => \App\Support\DemoData::taka($invoice->total),
            'paid' => \App\Support\DemoData::taka($invoice->paid_amount),
            'balance' => \App\Support\DemoData::taka($invoice->balance),
            'issued' => $issued['full'] ?? null,
            'issued_day' => $issued['day'] ?? null,
            'issued_date' => $issued['date'] ?? null,
            'issued_time' => $issued['time'] ?? null,
            'due' => $dueFull,
            'due_day' => $dueDay,
            'notes' => $invoice->notes,
            'link' => route('invoices.show', $invoice, false),
            'download' => route('invoices.download', $invoice, false),
        ];
    }

    /**
     * @param  Collection<int, Payment>  $payments
     * @return list<array{at:string, day:?string, date:?string, time:?string, title:string, detail:?string, by:?string, tone:string}>
     */
    private function buildPurchaseTimeline(Order $order, Collection $payments): array
    {
        $events = collect();

        $push = function (?Carbon $when, string $title, ?string $detail, ?string $by, string $tone) use ($events) {
            if (! $when) {
                return;
            }
            $stamp = $this->stamp($when);
            $events->push([
                'sort' => $when->timestamp,
                'at' => $stamp['full'],
                'day' => $stamp['day'],
                'date' => $stamp['date'],
                'time' => $stamp['time'],
                'title' => $title,
                'detail' => $detail,
                'by' => $by,
                'tone' => $tone,
            ]);
        };

        $push($order->submitted_at, 'Order submitted', 'Source: '.ucfirst(str_replace('_', ' ', $order->source)).' · Total '.\App\Support\DemoData::taka($order->total), $order->creator?->name ?? $order->salesman?->name, 'info');
        $advanceShown = $order->advance_amount ?? $order->advanceInvoice?->total;
        $remainingAfterAdvance = max(0, round((float) $order->total - (float) ($advanceShown ?: 0), 2));
        $push(
            $order->advance_requested_at,
            'Advance requested',
            'Advance '.\App\Support\DemoData::taka($advanceShown)
                .' of order '.\App\Support\DemoData::taka($order->total)
                .' · Remaining after advance '.\App\Support\DemoData::taka($remainingAfterAdvance)
                .($order->advanceInvoice ? ' · Invoice '.$order->advanceInvoice->number : ''),
            $order->auditor?->name,
            'warn',
        );
        $push(
            $order->advance_paid_at,
            'Advance paid in full',
            'Advance '.\App\Support\DemoData::taka($advanceShown)
                .' received'
                .($order->advanceInvoice ? ' · Invoice '.$order->advanceInvoice->number : '')
                .' · Remaining '.\App\Support\DemoData::taka($remainingAfterAdvance)
                .' still due on final invoice',
            null,
            'ok',
        );
        $push($order->audited_at, 'Order approved · stock reserved', $order->warehouse?->name ? 'Warehouse: '.$order->warehouse->name : ($order->audit_notes ?: null), $order->auditor?->name, 'ok');

        if ($order->status === Order::STATUS_REJECTED) {
            $push($order->audited_at, 'Order rejected', $order->rejection_reason, $order->auditor?->name, 'bad');
        }
        $push($order->cancelled_at, 'Order cancelled', $order->cancellation_reason, $order->canceller?->name, 'muted');

        foreach ($order->statusHistories as $history) {
            /** @var OrderStatusHistory $history */
            $label = $this->historyEventLabel($history);
            $push(
                $history->created_at,
                $label,
                $history->notes,
                $history->user?->name,
                $this->historyEventTone($history),
            );
        }

        $f = $order->fulfilment;
        if ($f) {
            $push($f->picking_started_at, 'Picking started', $f->warehouse?->name, $f->picker?->name, 'info');
            $push($f->picked_at, 'Picking completed', null, $f->picker?->name, 'ok');
            $push($f->packed_at, 'Packed', null, $f->packer?->name, 'ok');
            $push($f->dispatched_at, 'Dispatched from warehouse', null, $f->dispatcher?->name, 'ok');
            $push($f->delivered_at, 'Delivered to shop', null, null, 'ok');
        }

        $d = $f?->delivery;
        if ($d) {
            $push($d->dispatched_at, 'Out for delivery', $d->tracking_ref ? 'Tracking '.$d->tracking_ref : $d->number, $d->assignee?->name, 'info');
            $push($d->delivered_at, 'Delivery confirmed', $d->delivery_address, $d->assignee?->name, 'ok');
        }

        if ($order->advanceInvoice?->issued_at) {
            $push($order->advanceInvoice->issued_at, 'Advance invoice issued', $order->advanceInvoice->number.' · '.\App\Support\DemoData::taka($order->advanceInvoice->total), null, 'info');
        }
        if ($order->invoice?->issued_at && (int) $order->invoice_id !== (int) $order->advance_invoice_id) {
            $push($order->invoice->issued_at, 'Sales invoice issued', $order->invoice->number.' · '.\App\Support\DemoData::taka($order->invoice->total), null, 'info');
        }

        foreach ($payments as $payment) {
            $kind = $payment->invoice_id && (int) $payment->invoice_id === (int) $order->advance_invoice_id
                ? 'Advance payment'
                : 'Invoice payment';
            $push(
                $payment->paid_at ?: $payment->created_at,
                $kind.' · '.$payment->methodLabel(),
                \App\Support\DemoData::taka($payment->amount)
                    .($payment->reference ? ' · Ref '.$payment->reference : '')
                    .' · '.$payment->statusLabel(),
                $payment->creator?->name,
                $payment->status === Payment::STATUS_VERIFIED ? 'ok' : ($payment->status === Payment::STATUS_REJECTED ? 'bad' : 'warn'),
            );
            if ($payment->verified_at && $payment->status === Payment::STATUS_VERIFIED) {
                $push($payment->verified_at, 'Payment verified', $payment->number, $payment->verifier?->name, 'ok');
            }
        }

        return $events
            ->sortByDesc('sort')
            ->unique(fn ($e) => $e['title'].'|'.$e['at'].'|'.($e['detail'] ?? ''))
            ->values()
            ->map(fn ($e) => collect($e)->except('sort')->all())
            ->all();
    }

    private function historyEventLabel(OrderStatusHistory $history): string
    {
        return match ($history->event) {
            'submitted_for_audit' => 'Submitted for audit',
            'advance_requested' => 'Advance requested',
            'advance_paid' => 'Advance marked paid',
            'approved_reserved' => 'Approved & stock reserved',
            'rejected' => 'Rejected',
            'cancelled' => 'Cancelled',
            'deleted_before_audit' => 'Deleted before approval',
            'edited_before_audit' => 'Edited before audit',
            default => $history->event
                ? ucfirst(str_replace('_', ' ', $history->event))
                : 'Status → '.ucfirst(str_replace('_', ' ', (string) $history->to_status)),
        };
    }

    private function historyEventTone(OrderStatusHistory $history): string
    {
        return match ($history->event) {
            'rejected', 'cancelled', 'deleted_before_audit' => 'bad',
            'approved_reserved', 'advance_paid' => 'ok',
            'advance_requested' => 'warn',
            default => 'info',
        };
    }

    /**
     * @return array{full:string, day:string, date:string, time:string}|array{}
     */
    private function stamp(mixed $value): array
    {
        if (! $value) {
            return [];
        }

        $dt = $value instanceof Carbon ? $value : Carbon::parse($value);

        return [
            'full' => $dt->timezone(config('app.timezone'))->format('l, d M Y · h:i A'),
            'day' => $dt->timezone(config('app.timezone'))->format('l'),
            'date' => $dt->timezone(config('app.timezone'))->format('d M Y'),
            'time' => $dt->timezone(config('app.timezone'))->format('h:i A'),
        ];
    }

    private function statusBadgeClass(Order|string $orderOrStatus): string
    {
        if ($orderOrStatus instanceof Order) {
            if ($orderOrStatus->isAwaitingAdvance() && $orderOrStatus->isAdvancePaid()) {
                return 'badge-approved';
            }
            $status = $orderOrStatus->status;
        } else {
            $status = $orderOrStatus;
        }

        return match ($status) {
            Order::STATUS_PENDING_AUDIT, Order::STATUS_AWAITING_ADVANCE => 'badge-pending',
            Order::STATUS_APPROVED, Order::STATUS_PICKING, Order::STATUS_PICKED,
            Order::STATUS_PACKED, Order::STATUS_DISPATCHED => 'badge-approved',
            Order::STATUS_DELIVERED => 'badge-delivered',
            Order::STATUS_REJECTED => 'badge-out',
            default => 'badge-hold',
        };
    }

    private function redirectAfterAudit(Request $request, Order $order, string $message, string $result = 'updated', ?string $invoiceDownload = null)
    {
        $return = $request->input('return_url');
        $flash = [
            'order_id' => $order->id,
            'number' => $order->number,
            'status' => $order->statusLabel(),
            'result' => $result,
        ];

        $redirect = is_string($return) && str_starts_with($return, '/admin/orders')
            ? redirect()->to($return)
            : redirect()->route('orders.show', $order);

        $redirect = $redirect
            ->with('success', $message)
            ->with('status_flash', $flash);

        if ($invoiceDownload) {
            $redirect = $redirect->with('invoice_download', $invoiceDownload);
        }

        return $redirect;
    }
}
