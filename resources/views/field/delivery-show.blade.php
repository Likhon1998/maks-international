@extends('field.layouts.app')
@section('title', $delivery->number)
@section('heading', $delivery->number)
@section('subheading', ($delivery->shop?->name ?: 'Shop').' · '.$delivery->statusLabel())
@section('back', route('field.deliveries'))
@section('content')
    @if (session('success'))
        <div class="f-ok" style="margin-bottom:12px">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="f-warn" style="margin-bottom:12px">{{ $errors->first() }}</div>
    @endif

    <section class="f-panel" style="margin-bottom:12px">
        <div class="f-panel-head"><strong>Shop details</strong></div>
        <div style="padding:4px 0 2px">
            <div class="strong" style="font-size:16px">{{ $delivery->shop?->name ?: '—' }}</div>
            @if ($delivery->shop?->owner_name)
                <div class="f-muted" style="margin-top:4px;font-size:13px">Owner: {{ $delivery->shop->owner_name }}</div>
            @endif
            <div style="margin-top:8px;font-size:14px">{{ $delivery->delivery_address ?: ($delivery->shop?->address ?: '—') }}</div>
            @if ($delivery->shop?->phone)
                <a class="f-btn f-btn-ghost f-btn-sm" style="margin-top:10px" href="tel:{{ preg_replace('/\s+/', '', $delivery->shop->phone) }}">
                    <i data-lucide="phone"></i> {{ $delivery->shop->phone }}
                </a>
            @endif
        </div>
    </section>

    <div class="f-kpis" style="margin-bottom:12px">
        <div class="f-kpi tone-sky">
            <div class="f-kpi-label">Ordered</div>
            <div class="f-kpi-value">{{ $delivery->items->sum('qty_ordered') }}</div>
        </div>
        <div class="f-kpi tone-violet">
            <div class="f-kpi-label">Received</div>
            <div class="f-kpi-value">{{ $delivery->items->sum('qty_received') }}</div>
        </div>
        <div class="f-kpi tone-green">
            <div class="f-kpi-label">Delivered</div>
            <div class="f-kpi-value">{{ $delivery->items->sum('qty_delivered') }}</div>
        </div>
        <div class="f-kpi tone-amber">
            <div class="f-kpi-label">With you</div>
            <div class="f-kpi-value">{{ $delivery->remainingQty() }}</div>
        </div>
    </div>

    @if (! $delivery->isFullyReceived() && $delivery->status !== \App\Models\Delivery::STATUS_DELIVERED)
        <section class="f-panel" style="margin-bottom:12px">
            <div class="f-panel-head"><strong>1. Receive for delivery</strong></div>
            <p class="f-muted" style="margin:0 0 10px;font-size:13px">Confirm how many units you took from the warehouse.</p>
            <form method="post" action="{{ route('field.deliveries.receive', $delivery) }}">
                @csrf
                <div class="f-table-wrap">
                    <table class="f-table">
                        <thead><tr><th>Product</th><th class="num">Ordered</th><th class="num">Received</th></tr></thead>
                        <tbody>
                            @foreach ($delivery->items as $i => $item)
                                <tr>
                                    <td>
                                        <span class="strong">{{ $item->product_name }}</span>
                                        <span class="sub">{{ $item->product_sku }}</span>
                                        <input type="hidden" name="items[{{ $i }}][id]" value="{{ $item->id }}">
                                    </td>
                                    <td class="num">{{ $item->qty_ordered }}</td>
                                    <td class="num">
                                        <input class="f-input" style="width:88px;text-align:right" type="number" min="0" max="{{ $item->qty_ordered }}" name="items[{{ $i }}][qty]" value="{{ old('items.'.$i.'.qty', $item->qty_ordered) }}" required>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <button class="f-btn f-btn-primary f-btn-block" style="margin-top:12px" type="submit">Save received quantities</button>
            </form>
        </section>
    @endif

    @if ($delivery->isFullyReceived())
        <section class="f-panel" style="margin-bottom:12px">
            <div class="f-panel-head"><strong>2. Delivered to shop</strong></div>
            <p class="f-muted" style="margin:0 0 10px;font-size:13px">Enter how many units the shop accepted. Unsold units go to <strong>Returns</strong> for admin verification — they are not added to inventory automatically.</p>
            <form method="post" action="{{ route('field.deliveries.quantities', $delivery) }}">
                @csrf
                <input type="hidden" name="complete" value="1">
                <div class="f-table-wrap">
                    <table class="f-table">
                        <thead><tr><th>Product</th><th class="num">Received</th><th class="num">Delivered</th><th class="num">Left</th></tr></thead>
                        <tbody>
                            @foreach ($delivery->items as $i => $item)
                                <tr>
                                    <td>
                                        <span class="strong">{{ $item->product_name }}</span>
                                        <span class="sub">{{ $item->product_sku }}</span>
                                        <input type="hidden" name="items[{{ $i }}][id]" value="{{ $item->id }}">
                                    </td>
                                    <td class="num">{{ $item->qty_received }}</td>
                                    <td class="num">
                                        @if ($delivery->status === \App\Models\Delivery::STATUS_DELIVERED)
                                            {{ $item->qty_delivered }}
                                        @else
                                            <input class="f-input" style="width:88px;text-align:right" type="number" min="0" max="{{ $item->qty_received }}" name="items[{{ $i }}][qty]" value="{{ old('items.'.$i.'.qty', $item->qty_delivered ?: $item->qty_received) }}" required>
                                        @endif
                                    </td>
                                    <td class="num">{{ $item->qtyRemaining() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($delivery->status !== \App\Models\Delivery::STATUS_DELIVERED)
                    <button class="f-btn f-btn-primary f-btn-block" style="margin-top:12px" type="submit">Save delivered quantities</button>
                @endif
            </form>
        </section>

        @if ($delivery->remainingQty() > 0)
            <section class="f-panel" style="margin-bottom:12px">
                <div class="f-panel-head"><strong>3. Submit unsold stock for return</strong></div>
                <p class="f-muted" style="margin:0 0 10px;font-size:13px">Send remaining units to the Returns queue. Admin/manager must verify before stock is added back to inventory.</p>
                <form method="post" action="{{ route('field.deliveries.return', $delivery) }}">
                    @csrf
                    <div class="f-table-wrap">
                        <table class="f-table">
                            <thead><tr><th>Product</th><th class="num">With you</th><th class="num">Return</th></tr></thead>
                            <tbody>
                                @foreach ($delivery->items as $i => $item)
                                    @continue($item->qtyRemaining() <= 0)
                                    <tr>
                                        <td>
                                            <span class="strong">{{ $item->product_name }}</span>
                                            <input type="hidden" name="items[{{ $i }}][id]" value="{{ $item->id }}">
                                        </td>
                                        <td class="num">{{ $item->qtyRemaining() }}</td>
                                        <td class="num">
                                            <input class="f-input" style="width:88px;text-align:right" type="number" min="0" max="{{ $item->qtyRemaining() }}" name="items[{{ $i }}][qty]" value="{{ $item->qtyRemaining() }}">
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <button class="f-btn f-btn-ghost f-btn-block" style="margin-top:12px" type="submit">Submit to Returns</button>
                </form>
            </section>
        @endif
    @endif

    <div class="f-shop-foot">
        <a class="f-btn f-btn-ghost f-btn-sm" href="{{ route('field.deliveries.stock') }}"><i data-lucide="package"></i> My stock summary</a>
        @if ($delivery->order)
            <a class="f-btn f-btn-ghost f-btn-sm" href="{{ route('field.orders.show', $delivery->order) }}"><i data-lucide="receipt-text"></i> Order</a>
        @endif
    </div>
@endsection
