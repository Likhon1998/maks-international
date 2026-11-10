@php
    $statusClass = match ($invoice->status) {
        'paid' => 'inv-doc-badge-paid',
        'partial' => 'inv-doc-badge-partial',
        'issued' => 'inv-doc-badge-issued',
        default => '',
    };
    $issued = $invoice->issued_at;
    $kind = $documentKind ?? 'Sales Invoice';
    $settle = $invoice->order ? $invoice->order->settlement() : null;
    $payments = $invoice->payments ?? collect();
@endphp
<div class="inv-doc">
    <div class="inv-doc-band"></div>

    <div class="inv-doc-head">
        <div class="inv-doc-head-left">
            <div class="inv-doc-brand">{{ $site->siteName() }}</div>
            <div class="inv-doc-muted">Wholesale · Bangladesh · Asia/Dhaka</div>
        </div>
        <div class="inv-doc-head-right">
            <div class="inv-doc-title">{{ $kind }}</div>
            <div class="inv-doc-number">{{ $invoice->number }}</div>
            <span class="inv-doc-badge {{ $statusClass }}">{{ $invoice->statusLabel() }}</span>
        </div>
    </div>

    <table class="inv-doc-info">
        <tr>
            <td class="inv-doc-info-bill">
                <div class="inv-doc-label">Bill to</div>
                <div class="inv-doc-strong">{{ $invoice->shop?->name }}</div>
                @if ($invoice->shop?->code)<div class="inv-doc-muted">{{ $invoice->shop->code }}</div>@endif
                @if ($invoice->shop?->phone)<div class="inv-doc-muted">{{ $invoice->shop->phone }}</div>@endif
                @if ($invoice->shop?->address)<div class="inv-doc-muted">{{ \Illuminate\Support\Str::limit($invoice->shop->address, 60) }}</div>@endif
            </td>
            <td class="inv-doc-info-meta">
                <table class="inv-doc-meta-table">
                    @if ($invoice->order)
                        <tr><td>Order</td><td>{{ $invoice->order->number }}</td></tr>
                    @endif
                    @if ($issued)
                        <tr>
                            <td>Issued</td>
                            <td>{{ $issued->timezone(config('app.timezone'))->format('D, d M Y · h:i A') }}</td>
                        </tr>
                    @endif
                    @if ($invoice->due_at)
                        <tr>
                            <td>Due</td>
                            <td>{{ $invoice->due_at->format('D, d M Y') }}</td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    @if ($settle)
        <table class="inv-doc-settle">
            <tr>
                <td>
                    <span>Order total</span>
                    <strong>{{ \App\Support\DemoData::taka($settle['order_total']) }}</strong>
                </td>
                <td>
                    <span>Advance paid</span>
                    <strong>{{ \App\Support\DemoData::taka($settle['advance_paid']) }}</strong>
                </td>
                <td class="is-rest">
                    <span>Remaining due</span>
                    <strong>{{ \App\Support\DemoData::taka($settle['remaining_due']) }}</strong>
                </td>
            </tr>
        </table>
        @if (str_contains($kind, 'Advance') && $settle['remaining_due'] > 0.009)
            <div class="inv-doc-ack">After this advance, remaining {{ \App\Support\DemoData::taka($settle['remaining_due']) }} is due on the final invoice.</div>
        @elseif (! str_contains($kind, 'Advance') && $settle['advance_paid'] > 0.009)
            <div class="inv-doc-ack">Final balance after advance {{ \App\Support\DemoData::taka($settle['advance_paid']) }} already received.</div>
        @endif
    @endif

    <table class="inv-doc-lines">
        <thead>
            <tr>
                <th style="width:28px">#</th>
                <th style="width:72px">SKU</th>
                <th>Product</th>
                <th class="num" style="width:40px">Qty</th>
                <th class="num" style="width:72px">Unit</th>
                <th class="num" style="width:80px">Line</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($invoice->items as $i => $item)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $item->product_sku }}</td>
                    <td>{{ $item->product_name }}</td>
                    <td class="num">{{ $item->quantity }}</td>
                    <td class="num">{{ \App\Support\DemoData::taka($item->unit_price) }}</td>
                    <td class="num">{{ \App\Support\DemoData::taka($item->line_total) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="inv-doc-muted center">No line items</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="inv-doc-bottom">
        <tr>
            <td class="inv-doc-pay-col">
                <div class="inv-doc-label">Payment history</div>
                <table class="inv-doc-pay">
                    <thead>
                        <tr>
                            <th>Ref</th>
                            <th>When (Dhaka)</th>
                            <th>Method</th>
                            <th class="num">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($payments as $payment)
                            @php $paidAt = $payment->paid_at ?? $payment->created_at; @endphp
                            <tr>
                                <td>
                                    {{ $payment->number }}
                                    @if ($payment->reference)<span class="inv-doc-muted"> · {{ $payment->reference }}</span>@endif
                                </td>
                                <td>
                                    @if ($paidAt)
                                        {{ $paidAt->timezone(config('app.timezone'))->format('d M Y · h:i A') }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ $payment->methodLabel() }} · {{ $payment->statusLabel() }}</td>
                                <td class="num">{{ \App\Support\DemoData::taka($payment->amount) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="inv-doc-muted center">No payments yet</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </td>
            <td class="inv-doc-tot-col">
                <table class="inv-doc-totals">
                    <tr>
                        <td>Subtotal</td>
                        <td class="num">{{ \App\Support\DemoData::taka($invoice->subtotal) }}</td>
                    </tr>
                    @if ((float) $invoice->discount_total > 0)
                        <tr>
                            <td>Discount</td>
                            <td class="num">-{{ \App\Support\DemoData::taka($invoice->discount_total) }}</td>
                        </tr>
                    @endif
                    <tr class="grand">
                        <td>Total</td>
                        <td class="num">{{ \App\Support\DemoData::taka($invoice->total) }}</td>
                    </tr>
                    <tr>
                        <td>Paid</td>
                        <td class="num">{{ \App\Support\DemoData::taka($invoice->paid_amount) }}</td>
                    </tr>
                    @if ((float) $invoice->credited_amount > 0)
                        <tr>
                            <td>Return credit</td>
                            <td class="num">-{{ \App\Support\DemoData::taka($invoice->credited_amount) }}</td>
                        </tr>
                    @endif
                    <tr class="bal">
                        <td>Balance due</td>
                        <td class="num">{{ \App\Support\DemoData::taka($invoice->balance) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if ($invoice->notes)
        <div class="inv-doc-notes">{{ \Illuminate\Support\Str::limit($invoice->notes, 160) }}</div>
    @endif

    <div class="inv-doc-footer">
        {{ now()->timezone(config('app.timezone'))->format('D, d M Y · h:i A') }} Asia/Dhaka
        · {{ $invoice->number }}
        @if ($invoice->creator) · {{ $invoice->creator->name }} @endif
    </div>
</div>
