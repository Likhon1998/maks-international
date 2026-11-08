@extends('portal.layouts.app')
@section('title', $order->number)
@section('content')
    <div class="toolbar">
        <div class="page-kicker"><strong>{{ $order->number }}</strong> / {{ $order->statusLabel() }}</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            @if ($order->canPartnerEdit())
                <a class="btn btn-primary" href="{{ route('portal.orders.edit', $order) }}">Edit order</a>
                <form method="post" action="{{ route('portal.orders.destroy', $order) }}" onsubmit="return confirm('Delete this order before approval?')">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-ghost" type="submit" style="color:#b91c1c;border-color:#fecaca">Delete order</button>
                </form>
            @endif
            <a class="btn btn-ghost" href="{{ route('portal.orders') }}">Back</a>
        </div>
    </div>

    @if (session('success'))
        <div class="card" style="padding:12px 16px;margin-bottom:14px;background:#e8f8ee;color:#15803d">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="card" style="padding:12px 16px;margin-bottom:14px;background:#fef2f2;color:#b91c1c">{{ $errors->first() }}</div>
    @endif

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:14px">
        <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">This order</div><div style="font-weight:800">{{ \App\Support\DemoData::taka($order->total) }}</div></div>
        @if ((float) $order->discount_total > 0)
            <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">Discount</div><div style="font-weight:800">− {{ \App\Support\DemoData::taka($order->discount_total) }}</div></div>
        @endif
        <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">Previous baki</div><div style="font-weight:800;color:{{ $order->previousShopBaki() > 0.009 ? '#c2410c' : 'inherit' }}">{{ \App\Support\DemoData::taka($order->previousShopBaki()) }}</div></div>
        @php
            $portalThisBaki = (float) $order->settlement()['remaining_due'];
            $portalPrevBaki = $order->previousShopBaki();
            $portalTotalBaki = round($portalThisBaki + $portalPrevBaki, 2);
        @endphp
        @if ($portalTotalBaki > 0.009)
            <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">Total baki</div><div style="font-weight:800;color:#c2410c">{{ \App\Support\DemoData::taka($portalTotalBaki) }}</div><div class="muted" style="font-size:11px;margin-top:2px">this order {{ \App\Support\DemoData::taka($portalThisBaki) }} + previous {{ \App\Support\DemoData::taka($portalPrevBaki) }}</div></div>
        @endif
        <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">Salesman</div><div style="font-weight:700">{{ $order->salesman?->name ?: '—' }}</div></div>
        <div class="card" style="padding:14px">
            <div class="muted" style="font-size:12px">Delivery</div>
            <div style="font-weight:700">
                @if ($order->delivery)
                    {{ $order->delivery->statusLabel() }}
                    @if ($order->delivery->assignee)
                        <span class="muted" style="font-weight:600;font-size:12px"> · {{ $order->delivery->assignee->name }}</span>
                    @endif
                @else
                    {{ $order->isDelivered() ? 'Delivered' : 'Not dispatched yet' }}
                @endif
            </div>
        </div>
        <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">Submitted</div><div style="font-weight:700">{{ $order->submitted_at?->format('d M Y H:i') }}</div></div>
    </div>

    @php
        $order->loadMissing(['advanceInvoice', 'invoice']);
        $settle = $order->settlement();
    @endphp
    @if ($settle['has_advance'] || $settle['invoice_issued'])
        <div class="card" style="padding:14px;margin-bottom:14px;border:1px solid {{ $settle['fully_settled'] ? '#bbf7d0' : '#fde68a' }};background:{{ $settle['fully_settled'] ? '#f0fdf4' : '#fffbeb' }}">
            <div style="font-weight:800;margin-bottom:8px;color:{{ $settle['fully_settled'] ? '#166534' : '#92400e' }}">
                @if ($settle['fully_settled'])
                    Payment fully settled
                @elseif ($settle['collectible'])
                    Invoice ready — amount due
                @elseif ($settle['has_advance'] && $settle['advance_cleared'])
                    Paid some — rest is baki
                @elseif ($settle['has_advance'])
                    Waiting for office to confirm shop payment
                @else
                    Pay whenever you can
                @endif
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px">
                <div>
                    <div class="muted" style="font-size:11px">Order total</div>
                    <div style="font-weight:800">{{ \App\Support\DemoData::taka($settle['order_total']) }}</div>
                </div>
                <div>
                    <div class="muted" style="font-size:11px">Paid so far</div>
                    <div style="font-weight:800">{{ \App\Support\DemoData::taka($settle['paid_to_date']) }}</div>
                </div>
                <div>
                    <div class="muted" style="font-size:11px">Baki</div>
                    <div style="font-weight:800;font-size:18px;color:{{ $settle['fully_settled'] ? '#166534' : '#b45309' }}">
                        {{ \App\Support\DemoData::taka($settle['remaining_due']) }}
                    </div>
                </div>
            </div>
            @if (! $settle['fully_settled'])
                <p class="muted" style="margin:10px 0 0;font-size:12px">
                    @if ($settle['collectible'] || $settle['baki'] > 0.009)
                        Pay some now if you can — the rest stays as baki.
                    @else
                        Office is confirming the amount paid at the shop.
                    @endif
                </p>
            @endif
        </div>
    @endif

    @if (! empty($pendingBill) && (empty($collectibleBalance) || $collectibleBalance <= 0.009))
        <div class="card" style="padding:14px;margin-bottom:14px;background:#fff7ed;color:#9a3412">
            Payment of <strong>{{ \App\Support\DemoData::taka($pendingBill->amount) }}</strong> is waiting for office confirmation.
        </div>
    @elseif (! empty($collectibleBalance) && $collectibleBalance > 0.009)
        <form class="card" style="padding:16px;margin-bottom:14px" method="post" action="{{ route('portal.orders.pay', $order) }}"
              x-data="{
                  due: {{ json_encode((float) $collectibleBalance) }},
                  paidNow: {{ json_encode((float) old('amount', 0)) }},
                  taka(n) { return '৳ ' + Math.round(Number(n) || 0).toLocaleString('en-IN'); },
                  baki() { return Math.max(0, Math.round((this.due - Number(this.paidNow || 0)) * 100) / 100); }
              }">
            @csrf
            <div style="font-weight:800;margin-bottom:4px">Pay this bill</div>
            <p class="muted" style="margin:0 0 12px;font-size:13px">
                Pay some now if you can — remaining stays as baki. You can pay at any stage.
                @if (! empty($pendingBill))
                    Office is confirming {{ \App\Support\DemoData::taka($pendingBill->amount) }}.
                @endif
            </p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
                <button type="button" class="btn btn-ghost btn-sm" :class="Number(paidNow) === 0 && 'btn-primary'" @click="paidNow = 0">Full baki</button>
                <button type="button" class="btn btn-ghost btn-sm" @click="paidNow = Math.round(due / 2)">Half now</button>
                <button type="button" class="btn btn-ghost btn-sm" @click="paidNow = Math.round(due)">Pay all</button>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px">
                <div class="field">
                    <label class="label">Pay now</label>
                    <input class="input" type="number" name="amount" min="0" step="1" :max="Math.round(due)" x-model.number="paidNow">
                </div>
                <div class="field">
                    <label class="label">Method</label>
                    <select class="select" name="method">
                        <option value="cash" @selected(old('method', 'cash') === 'cash')>Cash</option>
                        <option value="mobile_banking" @selected(old('method') === 'mobile_banking')>Mobile banking</option>
                        <option value="bank_transfer" @selected(old('method') === 'bank_transfer')>Bank transfer</option>
                        <option value="cheque" @selected(old('method') === 'cheque')>Cheque</option>
                    </select>
                </div>
                <div class="field">
                    <label class="label">Reference</label>
                    <input class="input" type="text" name="reference" value="{{ old('reference') }}" maxlength="120" placeholder="Optional">
                </div>
            </div>
            <div class="muted" style="margin-top:10px;font-size:13px">Baki left: <strong x-text="taka(baki())"></strong></div>
            <div style="margin-top:12px">
                <button class="btn btn-primary" type="submit" :disabled="Number(paidNow) <= 0">
                    Pay now
                </button>
            </div>
        </form>
    @endif

    @if ($order->isAwaitingAdvance())
        <div class="card" style="padding:12px 16px;margin-bottom:14px;background:#fff7ed;color:#9a3412">
            <strong>Next step:</strong>
            @if ($order->isAdvancePaid())
                Advance is paid — waiting for admin approval.
            @else
                Pay advance {{ \App\Support\DemoData::taka($order->advance_amount) }}
                @if ($order->advanceInvoice)
                    (invoice {{ $order->advanceInvoice->number }})
                @endif
                and inform admin for verification.
            @endif
        </div>
    @elseif ($order->isPendingAudit())
        <div class="card" style="padding:12px 16px;margin-bottom:14px;background:#fff7ed;color:#9a3412">
            Waiting for Super Admin audit. Stock is not reserved yet.
            @if ($order->canPartnerEdit())
                You can still <a class="link" href="{{ route('portal.orders.edit', $order) }}">edit</a> or delete this order before approval.
            @endif
        </div>
    @elseif ($order->stock_reserved)
        <div class="card" style="padding:12px 16px;margin-bottom:14px;background:#e8f8ee;color:#15803d">Approved — stock reserved. Warehouse fulfilment comes next.</div>
    @elseif ($order->status === \App\Models\Order::STATUS_REJECTED)
        <div class="card" style="padding:12px 16px;margin-bottom:14px;background:#fef2f2;color:#b91c1c">Rejected: {{ $order->rejection_reason }}</div>
    @endif

    <div class="card">
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>SKU</th><th>Product</th><th>Qty</th><th>Unit</th><th>Line</th></tr></thead>
                <tbody>
                    @foreach ($order->items as $item)
                        <tr>
                            <td>{{ $item->product_sku }}</td>
                            <td>{{ $item->product_name }}</td>
                            <td>{{ $item->quantity }}</td>
                            <td>{{ \App\Support\DemoData::taka($item->unit_price) }}</td>
                            <td style="font-weight:600">{{ \App\Support\DemoData::taka($item->line_total) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($order->notes)
            <div style="padding:14px;border-top:1px solid var(--line)"><span class="muted">Notes:</span> {{ $order->notes }}</div>
        @endif
    </div>
@endsection
