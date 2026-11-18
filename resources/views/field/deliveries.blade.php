@extends('field.layouts.app')
@section('title', 'Deliveries')
@section('heading', 'Deliveries')
@section('subheading', $counts['all'].' assigned to you · '.$counts['out'].' on the way')
@section('actions')
    <a class="f-btn f-btn-ghost f-btn-sm" href="{{ route('field.deliveries.stock') }}"><i data-lucide="package"></i> Stock</a>
    <a class="f-btn f-btn-ghost f-btn-sm" href="{{ route('field.orders') }}"><i data-lucide="receipt-text"></i> Orders</a>
@endsection
@section('content')
    @php
        $statusOptions = [
            'all' => ['label' => 'All', 'count' => $counts['all']],
            'assigned' => ['label' => 'Assigned', 'count' => $counts['assigned']],
            'out' => ['label' => 'On the way', 'count' => $counts['out']],
            'delivered' => ['label' => 'Delivered', 'count' => $counts['delivered']],
            'failed' => ['label' => 'Failed', 'count' => $counts['failed']],
        ];
        $activeKey = $filter ?? 'all';
        $activeLabel = $statusOptions[$activeKey]['label'].' ('.$statusOptions[$activeKey]['count'].')';
    @endphp

    <form class="f-del-toolbar" method="get" action="{{ route('field.deliveries') }}" role="search">
        <div class="f-search">
            <i data-lucide="search"></i>
            <input
                type="search"
                name="q"
                value="{{ $search }}"
                placeholder="Search shop, delivery, order, phone"
                autocomplete="off"
                aria-label="Search deliveries"
            >
        </div>
        <label class="f-del-status">
            <span class="f-sr-only">Status</span>
            <select name="status" aria-label="Filter by status" onchange="this.form.submit()">
                @foreach ($statusOptions as $key => $opt)
                    <option value="{{ $key === 'all' ? '' : $key }}" @selected($activeKey === $key)>
                        {{ $opt['label'] }} ({{ $opt['count'] }})
                    </option>
                @endforeach
            </select>
            <i data-lucide="chevron-down" aria-hidden="true"></i>
        </label>
        @if ($search !== '' || $filter)
            <a class="f-btn f-btn-ghost f-btn-sm" href="{{ route('field.deliveries') }}">Clear</a>
        @endif
        <noscript><button class="f-btn f-btn-primary f-btn-sm" type="submit">Apply</button></noscript>
    </form>

    @if ($search !== '')
        <p class="f-muted f-small" style="margin:0 0 8px">Showing {{ $activeLabel }} matching “{{ $search }}”</p>
    @endif

    <section class="f-panel f-desktop-only">
        <div class="f-table-wrap">
            <table class="f-table">
                <thead>
                    <tr>
                        <th>Delivery</th>
                        <th>Shop</th>
                        <th>Order</th>
                        <th>Contact</th>
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
                            <td>
                                <span class="strong">{{ $delivery->shop?->phone ?: '—' }}</span>
                                @if ($delivery->delivery_address)
                                    <span class="sub">{{ \Illuminate\Support\Str::limit($delivery->delivery_address, 36) }}</span>
                                @endif
                            </td>
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
                                @if ($search !== '' || $filter)
                                    No deliveries match this search.
                                @else
                                    No deliveries yet. When admin assigns a delivery to you, it shows up here.
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
                <strong>{{ ($search !== '' || $filter) ? 'No matches' : 'No deliveries yet' }}</strong>
                {{ ($search !== '' || $filter) ? 'Try another search or status.' : 'When admin assigns a delivery to you, it appears here.' }}
                @unless ($search !== '' || $filter)
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

@push('scripts')
<script>
(() => {
    const form = document.querySelector('.f-del-toolbar');
    const input = form?.querySelector('input[name="q"]');
    if (!form || !input) return;
    let timer;
    input.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(() => form.requestSubmit(), 350);
    });
})();
</script>
@endpush
