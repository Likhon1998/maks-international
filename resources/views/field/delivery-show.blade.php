@extends('field.layouts.app')
@section('title', $delivery->number)
@section('heading', $delivery->number)
@section('subheading', ($delivery->shop?->name ?: 'Shop').' · '.$delivery->statusLabel())
@section('back', route('field.deliveries'))
@section('content')
    @php
        $canReceive = $delivery->canReceiveStock();
        $canDeliver = $delivery->canRecordShopQuantities();
        $canReturn = $delivery->canSubmitUnsoldReturn();
        $isDone = $delivery->status === \App\Models\Delivery::STATUS_DELIVERED;
        $isFailed = $delivery->status === \App\Models\Delivery::STATUS_FAILED;
        $step = $canReceive ? 1 : ($canDeliver ? 2 : ($canReturn ? 3 : ($isDone ? 3 : 1)));
    @endphp

    @if ($isFailed)
        <div class="f-flash err" role="alert" style="margin-bottom:10px">
            <i data-lucide="alert-circle"></i>
            <span>This delivery failed. Ask office to retry and re-assign before you receive or deliver stock again.</span>
        </div>
    @endif

    <section class="f-panel" style="margin-bottom:10px">
        <div class="f-panel-head"><strong>Shop details</strong></div>
        <div class="f-panel-body" style="padding-top:8px">
            <div class="strong" style="font-size:15px">{{ $delivery->shop?->name ?: '—' }}</div>
            @if ($delivery->shop?->owner_name)
                <div class="f-muted" style="margin-top:2px;font-size:12.5px">Owner: {{ $delivery->shop->owner_name }}</div>
            @endif
            <div style="margin-top:6px;font-size:13px">{{ $delivery->delivery_address ?: ($delivery->shop?->address ?: '—') }}</div>
            @if ($delivery->shop?->phone)
                <a class="f-btn f-btn-ghost f-btn-sm" style="margin-top:8px" href="tel:{{ preg_replace('/\s+/', '', $delivery->shop->phone) }}">
                    <i data-lucide="phone"></i> {{ $delivery->shop->phone }}
                </a>
            @endif
        </div>
    </section>

    <div class="f-del-steps" aria-label="Delivery steps">
        <div class="f-del-step {{ $step === 1 ? 'is-active' : '' }} {{ ! $canReceive && $delivery->isFullyReceived() ? 'is-done' : '' }}">
            <span class="n">1</span><span>Receive</span>
        </div>
        <div class="f-del-step {{ $step === 2 ? 'is-active' : '' }} {{ $isDone ? 'is-done' : '' }}">
            <span class="n">2</span><span>Deliver</span>
        </div>
        <div class="f-del-step {{ $step === 3 ? 'is-active' : '' }} {{ $isDone && ! $canReturn ? 'is-done' : '' }}">
            <span class="n">3</span><span>Done</span>
        </div>
    </div>

    <div class="f-kpis" style="margin-bottom:10px">
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

    @if ($delivery->items->isEmpty())
        <div class="f-flash err" role="alert">
            <i data-lucide="alert-circle"></i>
            <span>No products on this delivery. Ask office to check the order lines.</span>
        </div>
    @endif

    @if ($canReceive && $delivery->items->isNotEmpty())
        <section class="f-panel" style="margin-bottom:10px">
            <div class="f-panel-head"><strong>1. Receive for delivery</strong></div>
            <div class="f-panel-body">
                <p class="f-muted" style="margin:0 0 8px;font-size:12.5px">Confirm how many units you took from the warehouse. Use 0 if a line was not loaded.</p>
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
                    <button class="f-btn f-btn-primary f-btn-block" style="margin-top:10px" type="submit">Save received quantities</button>
                </form>
            </div>
        </section>
    @endif

    @if ($delivery->isFullyReceived() && $delivery->items->isNotEmpty())
        <section class="f-panel" style="margin-bottom:10px">
            <div class="f-panel-head"><strong>2. Delivered to shop</strong></div>
            <div class="f-panel-body">
                @if ($isFailed)
                    <p class="f-muted" style="margin:0;font-size:12.5px">Delivery failed — shop quantities are locked until office retries.</p>
                @else
                    <p class="f-muted" style="margin:0 0 8px;font-size:12.5px">
                        Enter how many units the shop accepted.
                        @unless ($isDone)
                            Leftover units go to <strong>Returns</strong> for admin verification — not added to inventory automatically.
                        @endunless
                    </p>
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
                                                @if ($isDone)
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
                        @if ($canDeliver)
                            <button class="f-btn f-btn-primary f-btn-block" style="margin-top:10px" type="submit">Save &amp; complete delivery</button>
                        @endif
                    </form>
                @endif
            </div>
        </section>
    @endif

    @if ($canReturn)
        <section class="f-panel" style="margin-bottom:10px">
            <div class="f-panel-head"><strong>3. Submit leftover stock</strong></div>
            <div class="f-panel-body">
                <p class="f-muted" style="margin:0 0 8px;font-size:12.5px">Send remaining units to the Returns queue. Admin must verify before inventory updates.</p>
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
                    <button class="f-btn f-btn-ghost f-btn-block" style="margin-top:10px" type="submit">Submit to Returns</button>
                </form>
            </div>
        </section>
    @elseif ($isDone)
        <div class="f-flash ok" role="status" style="margin-bottom:10px">
            <i data-lucide="check-circle-2"></i>
            <span>
                Delivery complete.
                @if ($delivery->remainingQty() === 0)
                    No leftover stock with you.
                @endif
            </span>
        </div>
    @endif

    <div class="f-shop-foot">
        <a class="f-btn f-btn-ghost f-btn-sm" href="{{ route('field.deliveries.stock') }}"><i data-lucide="package"></i> My stock summary</a>
        @if ($delivery->order)
            <a class="f-btn f-btn-ghost f-btn-sm" href="{{ route('field.orders.show', $delivery->order) }}"><i data-lucide="receipt-text"></i> Order</a>
        @endif
    </div>
@endsection
