@extends('field.layouts.app')
@section('title', 'Deliveries')
@section('heading', 'Deliveries')
@section('subheading', $counts['all'].' deliveries · '.$counts['out'].' on the way')
@section('actions')
    <a class="f-btn f-btn-ghost f-btn-sm" href="{{ route('field.deliveries.stock') }}"><i data-lucide="package"></i> Stock</a>
    <a class="f-btn f-btn-ghost f-btn-sm" href="{{ route('field.orders') }}"><i data-lucide="receipt-text"></i> Orders</a>
@endsection
@section('content')
    @php
        $tabs = [
            'all' => 'All',
            'out' => 'On the way',
            'delivered' => 'Delivered',
            'failed' => 'Failed',
        ];
    @endphp

    <div class="f-kpis">
        <a class="f-kpi tone-sky" href="{{ route('field.deliveries', ['status' => 'out']) }}">
            <div class="f-kpi-top"><span class="f-ico"><i data-lucide="truck"></i></span><span class="f-kpi-label">On the way</span></div>
            <div class="f-kpi-value">{{ $counts['out'] }}</div>
            <div class="f-kpi-meta">Out for delivery</div>
        </a>
        <a class="f-kpi tone-green" href="{{ route('field.deliveries', ['status' => 'delivered']) }}">
            <div class="f-kpi-top"><span class="f-ico"><i data-lucide="package-check"></i></span><span class="f-kpi-label">Delivered</span></div>
            <div class="f-kpi-value">{{ $counts['delivered'] }}</div>
            <div class="f-kpi-meta">Reached the shop</div>
        </a>
        <a class="f-kpi tone-rose" href="{{ route('field.deliveries', ['status' => 'failed']) }}">
            <div class="f-kpi-top"><span class="f-ico"><i data-lucide="package-x"></i></span><span class="f-kpi-label">Failed</span></div>
            <div class="f-kpi-value">{{ $counts['failed'] }}</div>
            <div class="f-kpi-meta">Need follow-up</div>
        </a>
        <a class="f-kpi tone-violet" href="{{ route('field.deliveries') }}">
            <div class="f-kpi-top"><span class="f-ico"><i data-lucide="list"></i></span><span class="f-kpi-label">All</span></div>
            <div class="f-kpi-value">{{ $counts['all'] }}</div>
            <div class="f-kpi-meta">Your shops</div>
        </a>
    </div>

    <div class="f-filters">
        @foreach ($tabs as $key => $label)
            <a class="f-filter {{ ($filter ?? 'all') === $key ? 'active' : '' }}" href="{{ route('field.deliveries', $key === 'all' ? [] : ['status' => $key]) }}">
                {{ $label }} <span class="n">{{ $counts[$key] ?? 0 }}</span>
            </a>
        @endforeach
    </div>

    <section class="f-panel f-desktop-only">
        <div class="f-table-wrap">
            <table class="f-table">
                <thead>
                    <tr>
                        <th>Delivery</th>
                        <th>Shop</th>
                        <th>Order</th>
                        <th>Rider</th>
                        <th>When</th>
                        <th class="num">Amount</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($deliveries as $delivery)
                        @php
                            $when = $delivery->delivered_at ?? $delivery->dispatched_at;
                            $due = $delivery->order ? (float) $delivery->order->collectibleBalance() : 0;
                            $href = route('field.deliveries.show', $delivery);
                        @endphp
                        <tr @if ($href) class="is-link" onclick="window.location='{{ $href }}'" @endif>
                            <td>
                                <span class="strong">{{ $delivery->number }}</span>
                                @if ($delivery->tracking_ref)
                                    <span class="sub">Track {{ $delivery->tracking_ref }}</span>
                                @endif
                            </td>
                            <td>
                                <span class="strong">{{ $delivery->shop?->name ?: '—' }}</span>
                                <span class="sub">{{ $delivery->shop?->city ?: ($delivery->delivery_address ? \Illuminate\Support\Str::limit($delivery->delivery_address, 36) : '') }}</span>
                            </td>
                            <td>
                                @if ($delivery->order)
                                    <a class="strong" href="{{ route('field.orders.show', $delivery->order) }}" onclick="event.stopPropagation()">{{ $delivery->order->number }}</a>
                                @else
                                    <span class="f-muted">—</span>
                                @endif
                            </td>
                            <td>{{ $delivery->assignee?->name ?: '—' }}</td>
                            <td>
                                {{ $when?->format('d M Y') ?: '—' }}
                                <span class="sub">{{ $when?->format('g:i A') }}</span>
                            </td>
                            <td class="num strong">
                                {{ $delivery->order ? \App\Support\DemoData::taka($delivery->order->total) : '—' }}
                            </td>
                            <td>
                                <span class="f-status {{ $delivery->statusTone() }}">{{ $delivery->statusLabel() }}</span>
                                @if ($due > 0.009)
                                    <span class="f-collect-pill">Baki {{ \App\Support\DemoData::taka($due) }}</span>
                                @endif
                            </td>
                            <td class="num">@if ($href)<i data-lucide="chevron-right" class="f-chev"></i>@endif</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="empty">
                                @if ($filter)
                                    No deliveries in this list.
                                @else
                                    No deliveries yet. When your orders are dispatched, they show up here.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="f-mobile-only" style="display:grid;gap:0">
        @forelse ($deliveries as $delivery)
            @include('field.partials.delivery-row', ['delivery' => $delivery])
        @empty
            <div class="f-empty">
                <i data-lucide="truck"></i>
                <strong>{{ $filter ? 'No deliveries here' : 'No deliveries yet' }}</strong>
                {{ $filter ? 'Try another filter.' : 'When the office dispatches your shop orders, they appear here.' }}
                @unless ($filter)
                    <a class="f-btn f-btn-primary" style="margin-top:12px" href="{{ route('field.orders') }}"><i data-lucide="receipt-text"></i> View orders</a>
                @endunless
            </div>
        @endforelse
    </div>

    @if ($deliveries->hasPages())
        <div style="display:flex;gap:10px;justify-content:space-between;align-items:center;margin-top:14px">
            @if ($deliveries->onFirstPage())
                <span class="f-btn f-btn-ghost f-btn-sm" aria-disabled="true" style="opacity:.45">‹ Newer</span>
            @else
                <a class="f-btn f-btn-ghost f-btn-sm" href="{{ $deliveries->previousPageUrl() }}">‹ Newer</a>
            @endif
            <span class="f-muted f-small">Page {{ $deliveries->currentPage() }} of {{ $deliveries->lastPage() }}</span>
            @if ($deliveries->hasMorePages())
                <a class="f-btn f-btn-ghost f-btn-sm" href="{{ $deliveries->nextPageUrl() }}">Older ›</a>
            @else
                <span class="f-btn f-btn-ghost f-btn-sm" aria-disabled="true" style="opacity:.45">Older ›</span>
            @endif
        </div>
    @endif
@endsection
