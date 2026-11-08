@extends('layouts.app')
@section('title', $delivery->number)
@section('content')
    <x-page-header title="{{ $delivery->number }}" subtitle="{{ $delivery->shop?->name }} · {{ $delivery->statusLabel() }}">
        <a class="btn btn-ghost" href="{{ route('deliveries.index') }}">Back</a>
        <a class="btn btn-ghost" href="{{ route('orders.show', $delivery->order) }}">Order</a>
        <a class="btn btn-primary" href="{{ route('deliveries.challan', $delivery) }}">Download challan</a>
    </x-page-header>

    @if (session('success'))
        <div class="card" style="padding:12px 16px;margin-bottom:14px;background:#e8f8ee;color:#15803d">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="card" style="padding:12px 16px;margin-bottom:14px;background:#fef2f2;color:#b91c1c">{{ $errors->first() }}</div>
    @endif

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:14px">
        <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">Order</div><div style="font-weight:700">{{ $delivery->order?->number }}</div></div>
        <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">Warehouse</div><div style="font-weight:700">{{ $delivery->warehouse?->name }}</div></div>
        <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">Delivery man</div><div style="font-weight:700">{{ $delivery->assignee?->name ?: 'Unassigned' }}</div></div>
        <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">Tracking</div><div style="font-weight:700">{{ $delivery->tracking_ref ?: '—' }}</div></div>
    </div>

    <div class="card" style="padding:16px;margin-bottom:14px">
        <div class="muted" style="font-size:12px">Shop & delivery address</div>
        <div style="font-weight:700;margin-top:4px">{{ $delivery->shop?->name ?: '—' }}</div>
        <div style="margin-top:6px">{{ $delivery->delivery_address ?: ($delivery->shop?->address ?: '—') }}</div>
        @if ($delivery->shop?->phone)
            <div class="muted" style="margin-top:6px;font-size:13px">Contact: {{ $delivery->shop->phone }}</div>
        @endif
    </div>

    @if ($delivery->canBeAssigned())
        @can('deliveries.assign')
            <form class="card" style="padding:16px;margin-bottom:14px" method="post" action="{{ route('deliveries.assign', $delivery) }}">
                @csrf
                <div style="font-weight:800;margin-bottom:6px">{{ $delivery->assignee ? 'Change delivery man' : 'Assign delivery man' }}</div>
                <p class="muted" style="margin:0 0 12px;font-size:13px">Choose who will deliver this order. Assignment is manual — nothing is auto-assigned.</p>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-bottom:12px">
                    <div class="field">
                        <label class="label">Delivery man</label>
                        <select class="select" name="assigned_to" required>
                            <option value="">Select delivery man…</option>
                            @foreach ($deliveryMen as $man)
                                <option value="{{ $man->id }}" @selected((int) old('assigned_to', $delivery->assigned_to) === (int) $man->id)>
                                    {{ $man->name }}@if ($man->phone) · {{ $man->phone }}@endif
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label class="label">Notes</label>
                        <input class="input" type="text" name="notes" value="{{ old('notes') }}" maxlength="1000" placeholder="Optional">
                    </div>
                </div>
                @if (! in_array($delivery->status, [\App\Models\Delivery::STATUS_OUT_FOR_DELIVERY, \App\Models\Delivery::STATUS_DELIVERED], true))
                    <label class="muted" style="display:inline-flex;align-items:center;gap:6px;font-size:13px;margin-bottom:12px">
                        <input type="checkbox" name="mark_out" value="1" @checked(old('mark_out'))>
                        Also mark out for delivery
                    </label>
                @endif
                <div>
                    <button class="btn btn-primary" type="submit">{{ $delivery->assignee ? 'Update assignment' : 'Assign' }}</button>
                </div>
            </form>
        @endcan
    @endif

    @if (in_array($delivery->status, [\App\Models\Delivery::STATUS_ASSIGNED, \App\Models\Delivery::STATUS_PENDING], true) && $delivery->assigned_to)
        @can('deliveries.update_status')
            <form class="card" style="padding:16px;margin-bottom:14px" method="post" action="{{ route('deliveries.out', $delivery) }}">
                @csrf
                <div style="font-weight:800;margin-bottom:6px">Out for delivery</div>
                <p class="muted" style="margin:0 0 12px;font-size:13px">Mark when {{ $delivery->assignee?->name ?: 'the delivery man' }} leaves for the shop.</p>
                <button class="btn btn-primary" type="submit">Mark out for delivery</button>
            </form>
        @endcan
    @endif

    @if (in_array($delivery->status, [\App\Models\Delivery::STATUS_ASSIGNED, \App\Models\Delivery::STATUS_OUT_FOR_DELIVERY], true))
        @can('deliveries.update_status')
            @php
                $dueNow = $delivery->order ? (float) $delivery->order->settlement()['remaining_due'] : 0;
            @endphp
            <form id="deliver-form" class="card" style="padding:16px;margin-bottom:14px" method="post" action="{{ route('deliveries.deliver', $delivery) }}"
                  x-data="{
                      due: {{ json_encode($dueNow) }},
                      paidNow: {{ json_encode((float) old('collected_now', 0)) }},
                      taka(n) { return '৳ ' + Math.round(Number(n) || 0).toLocaleString('en-IN'); },
                      baki() { return Math.max(0, Math.round((this.due - Number(this.paidNow || 0)) * 100) / 100); }
                  }">
                @csrf
                <div style="font-weight:800;margin-bottom:6px">Goods at the shop</div>
                <p class="muted" style="margin:0 0 12px;font-size:13px">
                    Mark delivered when the products are with the owner.
                    @if ($dueNow > 0.009)
                        Bill due: <strong style="color:var(--text)">{{ \App\Support\DemoData::taka($dueNow) }}</strong>. Collect some now, or leave the rest as baki.
                    @else
                        Nothing left to collect — already paid at order.
                    @endif
                </p>
                @if ($dueNow > 0.009)
                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
                        <button type="button" class="btn btn-ghost btn-sm" :class="Number(paidNow) === 0 && 'btn-primary'" @click="paidNow = 0">Full baki</button>
                        <button type="button" class="btn btn-ghost btn-sm" @click="paidNow = Math.round(due / 2)">Half now</button>
                        <button type="button" class="btn btn-ghost btn-sm" @click="paidNow = Math.round(due)">Pay all</button>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:12px">
                        <div class="field">
                            <label class="label">Owner paid now</label>
                            <input class="input" type="number" name="collected_now" min="0" step="1" :max="Math.round(due)" x-model.number="paidNow">
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
                    <p class="muted" style="margin:0 0 12px;font-size:13px">Baki left: <strong x-text="taka(baki())"></strong></p>
                @endif
                <div class="field" style="margin-bottom:10px">
                    <label class="label">Delivery notes</label>
                    <textarea class="input" name="notes" rows="2">{{ old('notes', $delivery->notes) }}</textarea>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <button class="btn btn-primary" type="submit">Mark delivered</button>
                </div>
            </form>
            <form method="post" action="{{ route('deliveries.fail', $delivery) }}" onsubmit="return confirm('Mark this drop as failed?')" style="margin-bottom:14px">
                @csrf
                <input type="hidden" name="notes" value="{{ old('notes', $delivery->notes) }}">
                <button class="btn btn-ghost" type="submit">Mark failed</button>
            </form>
        @endcan
    @elseif ($delivery->status === \App\Models\Delivery::STATUS_FAILED)
        @can('deliveries.update_status')
            <form class="card" style="padding:16px;margin-bottom:14px" method="post" action="{{ route('deliveries.retry', $delivery) }}">
                @csrf
                <div class="field" style="margin-bottom:10px">
                    <label class="label">Retry notes</label>
                    <textarea class="input" name="notes" rows="2">{{ old('notes', $delivery->notes) }}</textarea>
                </div>
                <button class="btn btn-primary" type="submit">Queue for retry</button>
            </form>
        @endcan
    @endif

    <div class="card">
        <div style="padding:14px;font-weight:700;border-bottom:1px solid #eee">Order lines</div>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>SKU</th><th>Product</th><th>Qty</th></tr></thead>
                <tbody>
                    @foreach ($delivery->order->items as $item)
                        <tr>
                            <td>{{ $item->product_sku }}</td>
                            <td>{{ $item->product_name }}</td>
                            <td>{{ $item->quantity }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
