@extends('layouts.app')
@section('title', $order->number)
@section('content')
@php
    $simple = $order->simpleStatus();
    $statusTone = $payload['status_tone'] ?? 'muted';
    $timeline = $purchase['timeline'] ?? [];
    $invoices = $purchase['invoices'] ?? [];
    $payments = $purchase['payments'] ?? [];
    $flowSteps = [1 => 'Sent', 2 => 'Approved', 3 => 'Delivered'];
@endphp
<div class="order-page" x-data="{ tab: 'timeline', requireAdvance: false, auditTab: 'approve', previewId: null }" @keydown.escape.window="previewId = null">
    <x-page-header title="{{ $order->number }}" subtitle="{{ $order->shop?->name }} · {{ $order->shop?->code }} · {{ ucfirst(str_replace('_', ' ', $order->source)) }}">
        <span class="status-pill status-pill--{{ $statusTone }}">{{ $simple['label'] }}</span>
        <a class="btn btn-ghost" href="{{ route('orders.index') }}">Back to orders</a>
    </x-page-header>

    @if (session('success'))
        <div class="status-flash status-flash--{{ session('status_flash.result', 'updated') }}" role="status">
            <div class="status-flash-main">{{ session('success') }}</div>
            @if (session('invoice_download'))
                <div class="status-flash-meta" style="margin-top:8px">
                    <a class="btn btn-primary btn-sm" href="{{ session('invoice_download') }}">Download invoice PDF</a>
                </div>
            @endif
        </div>
    @endif
    @if ($errors->any())
        <div class="card" style="padding:12px 16px;margin-bottom:14px;background:#fef2f2;color:#b91c1c">
            <ul style="margin:0;padding-left:18px">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="order-page-summary">
        <div><span>Shop</span><strong>{{ $order->shop?->name ?: '—' }}</strong></div>
        <div><span>Shopkeeper</span><strong>{{ $order->shop?->owner_name ?: $order->shop?->name ?: '—' }}</strong></div>
        <div><span>Salesman</span><strong>{{ $order->salesman?->name ?: '—' }}</strong></div>
        <div><span>Submitted</span><strong>{{ $order->submitted_at?->format('l, d M Y · h:i A') ?: '—' }}</strong></div>
        <div><span>Lines</span><strong>{{ $order->item_count }}</strong></div>
        <div><span>This order</span><strong>{{ \App\Support\DemoData::taka($order->total) }}</strong></div>
        @if ((float) $order->discount_total > 0)
            <div><span>Discount</span><strong>− {{ \App\Support\DemoData::taka($order->discount_total) }}</strong></div>
        @endif
        <div>
            <span>Shop previous baki</span>
            <strong style="{{ $order->previousShopBaki() > 0.009 ? 'color:#c2410c' : '' }}">{{ \App\Support\DemoData::taka($order->previousShopBaki()) }}</strong>
        </div>
        @php
            $adminThisBaki = (float) $order->settlement()['remaining_due'];
            $adminPrevBaki = $order->previousShopBaki();
            $adminTotalBaki = round($adminThisBaki + $adminPrevBaki, 2);
        @endphp
        @if ($adminTotalBaki > 0.009)
            <div>
                <span>Total baki</span>
                <strong style="color:#c2410c">{{ \App\Support\DemoData::taka($adminTotalBaki) }}</strong>
                <small style="display:block;font-weight:500;font-size:11px;color:var(--muted)">this order {{ \App\Support\DemoData::taka($adminThisBaki) }} + previous {{ \App\Support\DemoData::taka($adminPrevBaki) }}</small>
            </div>
        @endif
            <div>
            <span>Stock</span>
            <strong style="color:{{ $snapshot['stock_ok'] ? '#15803d' : '#b91c1c' }}">
                {{ $snapshot['stock_ok'] ? 'Ready to reserve' : 'Shortfall' }}
            </strong>
        </div>
        @if ($order->isPendingAudit() || $order->isAwaitingAdvance())
            <div title="Outstanding is tracked as baki. The credit figure does not block approval.">
                <span>Shop dues</span>
                <strong>
                    {{ \App\Support\DemoData::taka($snapshot['credit_exposure']) }}
                    <small style="display:block;font-weight:500;font-size:11px;color:var(--muted)">this order {{ \App\Support\DemoData::taka($snapshot['credit_needed']) }} still due · does not block</small>
                </strong>
            </div>
        @endif
        </div>

    @if ($simple['step'] > 0)
        <div class="order-simple-steps">
            @foreach ($flowSteps as $n => $label)
                <div class="order-simple-step {{ $n < $simple['step'] || ($simple['step'] === 3 && $n === 3) ? 'is-done' : ($n === $simple['step'] ? 'is-now' : '') }}">
                    <span class="dot"></span>{{ $label }}
                </div>
            @endforeach
        </div>
    @endif

    @php $settlement = $order->settlement(); @endphp
    @if ($settlement['has_advance'] || $settlement['invoice_issued'])
        <div class="order-settlement {{ $settlement['fully_settled'] ? 'is-settled' : ($settlement['advance_cleared'] ? 'is-partial' : 'is-due') }}">
            <div class="order-settlement-head">
                <strong>Payment</strong>
                <span>
                    @if ($settlement['fully_settled'])
                        Fully settled
                    @elseif ($settlement['collectible'])
                        Collect anytime — remaining is baki
                    @elseif ($settlement['has_advance'] && ! $settlement['advance_cleared'])
                        Payment waiting for office verify
                    @elseif ($settlement['has_advance'])
                        Collected at shop — rest is baki
                    @else
                        Balance outstanding
                    @endif
                </span>
            </div>
            <div class="order-settlement-grid">
                <div>
                    <span>Order total</span>
                    <strong>{{ \App\Support\DemoData::taka($settlement['order_total']) }}</strong>
                </div>
                <div>
                    <span>Paid so far</span>
                    <strong>{{ \App\Support\DemoData::taka($settlement['paid_to_date']) }}</strong>
            </div>
                @if ($settlement['sales_paid'] > 0.009)
                    <div>
                        <span>After delivery</span>
                        <strong>{{ \App\Support\DemoData::taka($settlement['sales_paid']) }}</strong>
                    </div>
                @endif
                @if (($settlement['pending_sales'] ?? 0) > 0.009)
                    <div>
                        <span>Waiting confirm</span>
                        <strong>{{ \App\Support\DemoData::taka($settlement['pending_sales']) }}</strong>
                    </div>
                @endif
                <div class="order-settlement-rest">
                    <span>Baki (rest later)</span>
                    <strong>{{ \App\Support\DemoData::taka($settlement['remaining_due']) }}</strong>
                    <em>
                        @if ($settlement['fully_settled'])
                            Nothing left to collect
                        @elseif ($settlement['baki'] > 0.009)
                            Collect in parts at any stage
                        @elseif ($settlement['collectible'])
                            On sales invoice {{ $order->invoice->number }} — collect at the shop or from the portal
                        @else
                            Collect whenever the owner has cash
                        @endif
                    </em>
                    </div>
            </div>
        </div>
    @elseif (! in_array($order->status, [\App\Models\Order::STATUS_REJECTED, \App\Models\Order::STATUS_CANCELLED], true))
        <div class="order-settlement is-partial">
            <div class="order-settlement-head">
                <strong>Payment</strong>
                <span>Collect anytime</span>
            </div>
            <p class="muted" style="margin:8px 0 0;font-size:13px">
                Approve, then deliver. Money can be collected before or after — leftover stays as baki.
            </p>
        </div>
    @endif

    <div class="order-page-layout">
        <div class="order-page-main">
            {{-- Purchase dossier --}}
            <section class="card order-page-card">
                <div class="order-page-card-head">
                    <div>
                        <strong>Purchase history</strong>
                        <div class="muted" style="font-size:12px;margin-top:2px">Every stage, invoice, and payment with day · date · time</div>
                    </div>
                    <div class="order-history-tabs">
                        <button type="button" class="order-history-tab" :class="tab === 'timeline' && 'is-active'" @click="tab = 'timeline'">Timeline ({{ count($timeline) }})</button>
                        <button type="button" class="order-history-tab" :class="tab === 'invoices' && 'is-active'" @click="tab = 'invoices'">Invoices ({{ $invoicePreviews->count() }})</button>
                        <button type="button" class="order-history-tab" :class="tab === 'payments' && 'is-active'" @click="tab = 'payments'">Payments ({{ count($payments) }})</button>
                    </div>
                </div>

                <div class="order-page-card-body" x-show="tab === 'timeline'">
                    @forelse ($timeline as $ev)
                        <div class="order-timeline-item tone-{{ $ev['tone'] ?? 'info' }}">
                            <div class="order-timeline-stamp">
                                <span class="order-timeline-day">{{ $ev['day'] ?? '' }}</span>
                                <span class="order-timeline-date">{{ $ev['date'] ?? '' }}</span>
                                <span class="order-timeline-time">{{ $ev['time'] ?? '' }}</span>
                            </div>
                            <div class="order-timeline-body">
                                <strong>{{ $ev['title'] }}</strong>
                                @if (!empty($ev['detail']))
                                    <p>{{ $ev['detail'] }}</p>
                                @endif
                                @if (!empty($ev['by']))
                                    <em>By {{ $ev['by'] }}</em>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="order-history-empty">No stage history yet.</div>
                    @endforelse
                </div>

                <div class="order-page-card-body" x-show="tab === 'invoices'" x-cloak>
                    @forelse ($invoicePreviews as $preview)
                        @php $inv = $preview['invoice']; @endphp
                        <article class="order-invoice-card" style="margin-bottom:10px">
                            <div class="order-invoice-top">
                                <div>
                                    <span class="order-invoice-kicker">{{ $preview['title'] }}</span>
                                    <strong>{{ $inv->number }}</strong>
                                </div>
                                <span class="badge
                                    @if ($inv->status === 'paid') badge-paid
                                    @elseif ($inv->status === 'partial') badge-partial
                                    @elseif ($inv->status === 'issued') badge-pending
                                    @else badge-hold
                                    @endif">{{ $inv->statusLabel() }}</span>
                            </div>
                            <div class="order-invoice-amounts">
                                <div><span>Total</span><strong>{{ \App\Support\DemoData::taka($inv->total) }}</strong></div>
                                <div><span>Paid</span><strong>{{ \App\Support\DemoData::taka($inv->paid_amount) }}</strong></div>
                                <div><span>Balance</span><strong>{{ \App\Support\DemoData::taka($inv->balance) }}</strong></div>
                            </div>
                            @if ($inv->issued_at)
                                <div class="order-history-meta" style="margin-top:8px">
                                    Issued <strong>{{ $inv->issued_at->format('l, d M Y · h:i A') }}</strong>
            </div>
    @endif
                            <div class="order-invoice-actions">
                                <button type="button" class="btn btn-primary btn-sm" @click="previewId = {{ $inv->id }}">Preview</button>
                                <a class="btn btn-ghost btn-sm" href="{{ route('invoices.download', $inv) }}">Download PDF</a>
                                <a class="btn btn-ghost btn-sm" href="{{ route('invoices.show', $inv) }}">Open</a>
                            </div>
                        </article>
                    @empty
                        <div class="order-history-empty">No invoices raised for this purchase yet.</div>
                    @endforelse
                </div>

                <div class="order-page-card-body" x-show="tab === 'payments'" x-cloak>
                    @forelse ($payments as $pay)
                        <article class="order-history-item is-{{ $pay['status_key'] }}" style="margin-bottom:8px">
                            <div class="order-history-top">
                                <strong>{{ $pay['amount'] }}</strong>
                                <span class="badge
                                    @if ($pay['status_key'] === 'verified') badge-paid
                                    @elseif ($pay['status_key'] === 'pending') badge-pending
                                    @else badge-out
                                    @endif">{{ $pay['status'] }}</span>
                            </div>
                            <div class="order-history-meta">
                                {{ $pay['kind'] }} · {{ $pay['method'] }}
                                @if (!empty($pay['invoice'])) · {{ $pay['invoice'] }} @endif
                                @if (!empty($pay['number'])) · {{ $pay['number'] }} @endif
                            </div>
                            <div class="order-invoice-stamp">
                                <span class="order-invoice-kicker">Paid</span>
                                <div class="order-timeline-stamp">
                                    <span class="order-timeline-day">{{ $pay['paid_day'] ?? '' }}</span>
                                    <span class="order-timeline-date">{{ $pay['paid_date'] ?? $pay['paid_at'] }}</span>
                                    <span class="order-timeline-time">{{ $pay['paid_time'] ?? '' }}</span>
                                </div>
                            </div>
                            @if (!empty($pay['verified_at']))
                                <div class="order-invoice-stamp">
                                    <span class="order-invoice-kicker">Verified</span>
                                    <div class="order-timeline-stamp">
                                        <span class="order-timeline-day">{{ $pay['verified_day'] ?? '' }}</span>
                                        <span class="order-timeline-date">{{ $pay['verified_date'] ?? $pay['verified_at'] }}</span>
                                        <span class="order-timeline-time">{{ $pay['verified_time'] ?? '' }}</span>
                                    </div>
                                    @if (!empty($pay['verified_by']))
                                        <div class="order-history-meta" style="margin-top:2px">by {{ $pay['verified_by'] }}</div>
    @endif
                                </div>
    @endif
                            @if (!empty($pay['reference']) || !empty($pay['recorded_by']))
                                <div class="order-history-meta">
                                    @if (!empty($pay['reference'])) Ref: {{ $pay['reference'] }} @endif
                                    @if (!empty($pay['recorded_by'])) · Recorded by {{ $pay['recorded_by'] }} @endif
        </div>
    @endif
                        </article>
                    @empty
                        <div class="order-history-empty">No payments recorded for this purchase yet.</div>
                    @endforelse
                </div>
            </section>

            {{-- Items --}}
            <section class="card order-page-card">
                <div class="order-page-card-head">
                    <strong>Order items</strong>
                    <span class="muted" style="font-size:12px">{{ $order->item_count }} line(s) · {{ \App\Support\DemoData::taka($order->total) }}</span>
                </div>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                                <th>Item</th>
                                <th class="num">Qty</th>
                                <th class="num">Original</th>
                                <th class="num">Sold at</th>
                                <th class="num">Line</th>
                                <th>Date & time</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($order->items as $item)
                            <tr>
                                    <td>
                                        <div style="font-weight:700">{{ $item->product_name }}</div>
                                        <div class="muted" style="font-size:11px">{{ $item->product_sku }}</div>
                                    </td>
                                    <td class="num" style="font-weight:800">{{ $item->quantity }}</td>
                                    <td class="num">{{ \App\Support\DemoData::taka($item->originalUnitPrice()) }}</td>
                                    <td class="num" style="font-weight:700;{{ $item->wasPriceChanged() ? 'color:#b45309' : '' }}">
                                        {{ \App\Support\DemoData::taka($item->unit_price) }}
                                        @if ($item->wasPriceChanged())
                                            <div class="muted" style="font-size:10px;font-weight:600">
                                                @if ($order->source === \App\Models\Order::SOURCE_SALESMAN)
                                                    Changed by salesman
                                                @elseif ($order->source === \App\Models\Order::SOURCE_ADMIN)
                                                    Changed by admin
                                                @else
                                                    Price changed
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                    <td class="num" style="font-weight:700">{{ \App\Support\DemoData::taka($item->line_total) }}</td>
                                    <td>{{ $order->submitted_at?->format('d M Y · h:i A') ?: $item->created_at?->format('d M Y · h:i A') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ((float) $order->discount_total > 0)
                    <div style="padding:12px 14px;border-top:1px solid var(--line);display:flex;justify-content:space-between">
                        <span class="muted">Subtotal</span>
                        <strong>{{ \App\Support\DemoData::taka($order->subtotal) }}</strong>
                    </div>
                    <div style="padding:0 14px 12px;display:flex;justify-content:space-between">
                        <span class="muted">Discount</span>
                        <strong>− {{ \App\Support\DemoData::taka($order->discount_total) }}</strong>
                    </div>
            @endif
            @if ($order->notes)
                    <div style="padding:14px;border-top:1px solid var(--line)"><span class="muted">Shop notes:</span> {{ $order->notes }}</div>
            @endif
            </section>
        </div>

        <aside class="order-page-aside">
            @if ($order->fulfilment && ! $order->isDelivered())
                <div class="card" style="padding:14px;margin-bottom:12px">
                    <div style="font-weight:800;margin-bottom:4px">Warehouse</div>
                    <p class="muted" style="margin:0;font-size:12px">
                        {{ $order->warehouse?->name ?: $order->fulfilment->warehouse?->name }}
                        · {{ $order->fulfilment->statusLabel() }}
                        @if ($order->fulfilment->delivery?->assignee)
                            · rider {{ $order->fulfilment->delivery->assignee->name }}
                        @endif
                    </p>
                    <a class="btn btn-ghost btn-sm" style="margin-top:10px" href="{{ route('fulfilment.index', ['open' => $order->fulfilment->id, 'status' => $order->fulfilment->status]) }}">Open warehouse desk</a>
                    @if ($order->fulfilment->delivery)
                        <a class="btn btn-ghost btn-sm" style="margin-top:10px" href="{{ route('deliveries.show', $order->fulfilment->delivery) }}">Open delivery</a>
                    @endif
                </div>
            @endif

            @if ($order->rejection_reason)
                <div class="card" style="padding:12px 14px;margin-bottom:12px;background:#fef2f2;color:#b91c1c">Rejected: {{ $order->rejection_reason }}</div>
            @endif
            @if ($order->cancellation_reason)
                <div class="card" style="padding:12px 14px;margin-bottom:12px;background:#f3f4f6">Cancelled: {{ $order->cancellation_reason }}</div>
            @endif

            @if ($order->canDeliver())
                @canany(['orders.approve', 'deliveries.update_status'])
                    <form class="card" style="padding:14px;margin-bottom:12px" method="post" action="{{ route('orders.deliver', $order) }}"
                          onsubmit="return confirm('Mark this order as delivered?')">
                        @csrf
                        <div style="font-weight:800;margin-bottom:4px">Deliver</div>
                        <p class="muted" style="margin:0 0 10px;font-size:12px">
                            @if ($order->delivery?->assignee)
                                Assigned to {{ $order->delivery->assignee->name }}. Collection stays separate.
                            @else
                                Choose who delivers this order. Nothing is auto-assigned.
                            @endif
                        </p>
                        @unless ($order->delivery?->assigned_to)
                            <div class="field" style="margin-bottom:10px">
                                <label class="label">Delivery man</label>
                                <select class="select" name="assigned_to" required>
                                    <option value="">Select delivery man…</option>
                                    @foreach ($deliveryMen ?? [] as $man)
                                        <option value="{{ $man->id }}" @selected((string) old('assigned_to') === (string) $man->id)>
                                            {{ $man->name }}@if ($man->phone) · {{ $man->phone }}@endif
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        @endunless
                        <button class="btn btn-primary btn-sm" type="submit">Mark delivered</button>
                    </form>
                @endcanany
            @endif

            @can('update', $order)
                @if ($order->canApplyDiscount())
                    <form class="card" style="padding:14px;margin-bottom:12px" method="post" action="{{ route('orders.discount', $order) }}">
                        @csrf
                        <div style="font-weight:800;margin-bottom:4px">Reduce total</div>
                        <p class="muted" style="margin:0 0 10px;font-size:12px">Subtotal {{ \App\Support\DemoData::taka($order->subtotal) }}. Enter a discount to lower what the shop owes on this order.</p>
                        <div class="field" style="margin-bottom:10px">
                            <label class="label">Discount amount</label>
                            <input class="input" type="number" name="discount_total" min="0" step="0.01" max="{{ (float) $order->subtotal }}" value="{{ old('discount_total', $order->discount_total) }}">
                        </div>
                        <button class="btn btn-primary btn-sm" type="submit">Apply discount</button>
                    </form>
                @endif
            @endcan

            {{-- Advance --}}
            @if ($payload['awaiting_advance'] ?? false)
                <div class="card order-advance-box" style="margin-bottom:12px">
                    @if ($payload['advance_paid'] ?? false)
                        <strong style="color:#15803d">Advance paid</strong>
                        <div class="order-advance-amount">{{ $payload['advance_amount'] }}</div>
                        <p class="muted" style="margin:6px 0 0;font-size:12px">
                            You can approve &amp; reserve now.
                        </p>
                        <div class="order-settlement-mini">
                            <div><span>Order total</span><strong>{{ $payload['settlement']['order_total'] ?? \App\Support\DemoData::taka($order->total) }}</strong></div>
                            <div><span>Advance paid</span><strong>{{ $payload['settlement']['advance_paid'] ?? $payload['advance_amount'] }}</strong></div>
                            <div class="is-rest"><span>Remaining due</span><strong>{{ $payload['settlement']['remaining_due'] ?? '—' }}</strong></div>
                        </div>
                        <p class="muted" style="margin:8px 0 0;font-size:12px">
                            Remaining is acknowledged and will be collected on the final sales invoice after fulfilment.
                        </p>
                    @else
                        <strong>Advance due</strong>
                        <div class="order-advance-amount">{{ $payload['advance_amount'] }}</div>
                        <p class="muted" style="margin:4px 0 8px;font-size:12px">
                            Balance {{ $payload['advance_balance'] }}
                            @if (!empty($payload['advance_invoice_number'])) · Invoice {{ $payload['advance_invoice_number'] }} @endif
                        </p>
                        <div class="order-settlement-mini">
                            <div><span>Order total</span><strong>{{ $payload['settlement']['order_total'] ?? \App\Support\DemoData::taka($order->total) }}</strong></div>
                            <div class="is-rest"><span>After advance, remaining</span><strong>{{ $payload['settlement']['remaining_due'] ?? '—' }}</strong></div>
                        </div>
                        @if (!empty($payload['collect_advance_url']))
                            <form method="post" action="{{ $payload['collect_advance_url'] }}" class="order-decide-panel" style="gap:8px">
                                @csrf
                                <label class="order-field">
                                    <span class="order-field-label">Amount received</span>
                                    <input class="input" type="number" name="amount" step="0.01" min="0.01" value="{{ $payload['advance_balance_raw'] }}" required>
                                </label>
                                <label class="order-field">
                                    <span class="order-field-label">Method</span>
                                    <select class="select" name="method" required>
                                        <option value="cash">Cash</option>
                                        <option value="bank_transfer">Bank transfer</option>
                                        <option value="mobile_banking">Mobile banking</option>
                                        <option value="cheque">Cheque</option>
                                    </select>
                                </label>
                                <label class="order-field">
                                    <span class="order-field-label">Reference <em>(optional)</em></span>
                                    <input class="input" type="text" name="reference" placeholder="Txn / cheque no.">
                                </label>
                                <button class="btn btn-primary btn-block" type="submit">Mark advance received</button>
                            </form>
                        @endif
                        @if (!empty($payload['advance_invoice_id'] ?? null) || $order->advance_invoice_id)
                            <a class="btn btn-ghost btn-sm" style="margin-top:8px" href="{{ route('invoices.download', $order->advance_invoice_id) }}">Download advance invoice</a>
                        @endif
                    @endif
                </div>
            @endif

            {{-- Audit actions --}}
            @if ($payload['pending_audit'] ?? false)
                @if (($payload['can_approve'] ?? false) || ($payload['can_request_advance'] ?? false) || ($payload['can_reject'] ?? false))
                    <div class="card" style="padding:14px;margin-bottom:12px">
                        <div style="font-weight:800;margin-bottom:10px">Decide</div>

                        @if (!($payload['awaiting_advance'] ?? false))
                            <div class="order-decide-tabs" style="margin-bottom:10px">
                                <button type="button" class="order-decide-tab" :class="auditTab === 'approve' && 'is-active'" @click="auditTab = 'approve'">Approve</button>
                                @if ($payload['can_reject'] ?? false)
                                    <button type="button" class="order-decide-tab is-danger" :class="auditTab === 'reject' && 'is-active'" @click="auditTab = 'reject'">Reject</button>
                                @endif
                            </div>

                            <div x-show="auditTab === 'approve'">
                                <label class="order-override" style="border-color:#c7d2fe;background:#eef2ff;cursor:pointer;margin-bottom:10px">
                                    <input type="checkbox" x-model="requireAdvance">
                                    <span>
                                        <strong style="color:#3730a3">Require advance first</strong>
                                        <small style="color:#4338ca">Shop must pay before approval</small>
                                    </span>
                                </label>

                                <form method="post" action="{{ $payload['request_advance_url'] }}" x-show="requireAdvance" x-cloak>
                                    @csrf
                                    <label class="order-field">
                                        <span class="order-field-label">Note <em>(optional)</em></span>
                                        <input class="input" type="text" name="audit_notes" placeholder="Warehouse / credit note">
                                    </label>
                                    <label class="order-field">
                                        <span class="order-field-label">Advance amount (৳)</span>
                                        <input class="input" type="number" name="advance_amount" step="0.01" min="1" max="{{ $payload['total_raw'] }}" value="{{ old('advance_amount', round($payload['total_raw'] * 0.3, 2)) }}" required>
                                    </label>
                                    <button class="btn btn-primary btn-block" type="submit">Request advance</button>
                                </form>

                                @if ($payload['can_approve'] ?? false)
                                    <form method="post" action="{{ $payload['approve_url'] }}" x-show="!requireAdvance">
                                        @csrf
                                        <label class="order-field">
                                            <span class="order-field-label">Note <em>(optional)</em></span>
                                            <input class="input" type="text" name="audit_notes" placeholder="Warehouse / credit note">
                                        </label>
                                        @unless ($snapshot['stock_ok'])
                                            <p class="order-decide-hint">Stock shortfall — cannot approve.</p>
                                        @endunless
                                        <button class="btn btn-approve btn-block" type="submit" @disabled(! $snapshot['stock_ok'])>Approve &amp; reserve</button>
                                    </form>
                                @endif
                            </div>

                            @if ($payload['can_reject'] ?? false)
                                <form method="post" action="{{ $payload['reject_url'] }}" x-show="auditTab === 'reject'" x-cloak>
                                    @csrf
                                    <label class="order-field">
                                        <span class="order-field-label">Rejection reason</span>
                                        <input class="input" type="text" name="rejection_reason" required placeholder="Why reject?">
                                    </label>
                                    <button class="btn btn-reject btn-block" type="submit">Reject order</button>
                                </form>
                            @endif
                        @else
                            @if (($payload['can_approve'] ?? false) && ($payload['advance_paid'] ?? false))
                                <form method="post" action="{{ $payload['approve_url'] }}">
                                    @csrf
                                    <label class="order-field">
                                        <span class="order-field-label">Note <em>(optional)</em></span>
                                        <input class="input" type="text" name="audit_notes" placeholder="Warehouse note">
                                    </label>
                                    @unless ($snapshot['stock_ok'])
                                        <p class="order-decide-hint">Stock shortfall — cannot approve.</p>
                                    @endunless
                                    <button class="btn btn-approve btn-block" type="submit" @disabled(! $snapshot['stock_ok'])>Approve &amp; reserve</button>
                                </form>
                            @endif
                            @if ($payload['can_reject'] ?? false)
                                <form method="post" action="{{ $payload['reject_url'] }}" style="margin-top:10px">
                                    @csrf
                                    <label class="order-field">
                                        <span class="order-field-label">Rejection reason</span>
                                        <input class="input" type="text" name="rejection_reason" required placeholder="Why reject?">
                                    </label>
                                    <button class="btn btn-reject btn-block" type="submit">Reject order</button>
                                </form>
                            @endif
                        @endif

                        @if ($payload['can_delete'] ?? false)
                            <form method="post" action="{{ $payload['delete_url'] }}" style="margin-top:12px;padding-top:12px;border-top:1px dashed var(--line)" onsubmit="return confirm('Delete this order permanently?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn-link-danger" type="submit">Delete this order</button>
                            </form>
                        @endif
                    </div>
                @endif
            @endif

            @if ($order->isApproved() || $order->isPendingAudit() || $order->isAwaitingAdvance())
                @can('cancel', $order)
                    <div class="card" style="padding:14px;margin-bottom:12px">
                        <form method="post" action="{{ route('orders.cancel', $order) }}">
                            @csrf
                            <label class="order-field">
                                <span class="order-field-label">Cancel reason</span>
                                <input class="input" name="cancellation_reason" required placeholder="Reason for cancellation">
                            </label>
                            <button class="btn btn-ghost btn-block" type="submit">Cancel order{{ $order->isApproved() ? ' & release stock' : '' }}</button>
                        </form>
                    </div>
                @endcan
            @endif

            @if ($order->advance_invoice_id || $order->invoice_id)
                <div class="card" style="padding:14px;margin-bottom:12px">
                    <div style="font-weight:800;margin-bottom:8px">Invoices</div>
                    <div style="display:grid;gap:6px">
                        @foreach ($invoicePreviews as $preview)
                            <button
                                type="button"
                                class="btn btn-ghost btn-sm"
                                style="justify-content:flex-start"
                                @click="tab = 'invoices'; previewId = {{ $preview['invoice']->id }}"
                            >
                                Preview {{ strtolower($preview['title']) }}
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($order->invoice)
                <div class="card" style="padding:14px;margin-bottom:12px">
                    <div style="font-weight:800">Sales invoice</div>
                    <div class="muted" style="font-size:12px;margin:4px 0 10px">{{ $order->invoice->number }} · {{ $order->invoice->statusLabel() }}</div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap">
                        <button type="button" class="btn btn-primary btn-sm" @click="tab = 'invoices'; previewId = {{ $order->invoice->id }}">Preview</button>
                        <a class="btn btn-ghost btn-sm" href="{{ route('invoices.download', $order->invoice) }}">Download PDF</a>
                        <a class="btn btn-ghost btn-sm" href="{{ route('invoices.show', $order->invoice) }}">Open</a>
                    </div>
                </div>
            @elseif (! in_array($order->status, [\App\Models\Order::STATUS_REJECTED, \App\Models\Order::STATUS_CANCELLED], true))
                @can('invoices.create')
                    <div class="card" style="padding:14px;margin-bottom:12px">
                        <div style="font-weight:800;margin-bottom:6px">No sales invoice yet</div>
                        <p class="muted" style="font-size:12px;margin:0 0 8px">Raised automatically when money is collected, or raise it now.</p>
                        <form method="post" action="{{ route('orders.invoice', $order) }}">@csrf<button class="btn btn-primary btn-sm" type="submit">Raise invoice</button></form>
                    </div>
                @endcan
            @endif

            <div class="card" style="padding:14px">
                <div style="font-weight:800;margin-bottom:10px">Pipeline</div>
                <ol class="muted" style="padding-left:18px;line-height:1.85;margin:0;font-size:13px">
                    <li>Submitted</li>
                    <li style="{{ $order->stock_reserved || $order->canDeliver() || $order->isDelivered() ? 'color:#15803d;font-weight:600' : '' }}">Approved</li>
                    <li style="{{ $order->isDelivered() ? 'color:#15803d;font-weight:600' : '' }}">Delivered</li>
                </ol>
                <p class="muted" style="margin:10px 0 0;font-size:12px">Money can be collected at any of these stages.</p>
            </div>
        </aside>
    </div>

    {{-- Invoice preview modal (opens only on Preview click) --}}
    @foreach ($invoicePreviews as $preview)
        <div
            class="invoice-preview-modal"
            x-show="previewId === {{ $preview['invoice']->id }}"
            x-cloak
            @click.self="previewId = null"
        >
            <div class="invoice-preview-modal-panel" @click.stop>
                <div class="invoice-preview-toolbar">
                    <div>
                        <strong>{{ $preview['title'] }} preview</strong>
                        <div class="muted" style="font-size:12px">{{ $preview['invoice']->number }} · same layout as the PDF</div>
                    </div>
                    <div class="order-invoice-actions">
                        <a class="btn btn-primary btn-sm" href="{{ route('invoices.download', $preview['invoice']) }}">Download PDF</a>
                        <button type="button" class="btn btn-ghost btn-sm" @click="previewId = null">Close</button>
                    </div>
                </div>
                <div class="invoice-preview-sheet">
                    @include('admin.invoices.partials.document-body', [
                        'invoice' => $preview['invoice'],
                        'documentKind' => $preview['kind'],
                    ])
                </div>
            </div>
        </div>
    @endforeach
    </div>
@endsection
