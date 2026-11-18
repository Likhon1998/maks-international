@extends('field.layouts.app')
@section('title', __('field.collected_title'))
@section('heading', __('field.collected_title'))
@section('subheading', __('field.collected_sub'))
@section('actions')
    <a class="f-btn f-btn-primary f-btn-sm" href="{{ route('field.dues') }}"><i data-lucide="scale"></i> {{ __('field.collect_baki') }}</a>
@endsection
@section('content')
    <div class="f-kpis">
        <div class="f-kpi tone-green">
            <div class="f-kpi-top"><span class="f-ico"><i data-lucide="banknote"></i></span><span class="f-kpi-label">Received</span></div>
            <div class="f-kpi-value">{{ \App\Support\DemoData::taka($receivedTotal) }}</div>
            <div class="f-kpi-meta">Verified from shops</div>
        </div>
        <div class="f-kpi tone-blue">
            <div class="f-kpi-top"><span class="f-ico"><i data-lucide="coins"></i></span><span class="f-kpi-label">You collected</span></div>
            <div class="f-kpi-value">{{ \App\Support\DemoData::taka($youCollected) }}</div>
            <div class="f-kpi-meta">In your name</div>
        </div>
        <div class="f-kpi tone-amber">
            <div class="f-kpi-top"><span class="f-ico"><i data-lucide="hourglass"></i></span><span class="f-kpi-label">Waiting</span></div>
            <div class="f-kpi-value">{{ \App\Support\DemoData::taka($pendingTotal) }}</div>
            <div class="f-kpi-meta">Office to confirm</div>
        </div>
        <div class="f-kpi tone-rose">
            <div class="f-kpi-top"><span class="f-ico"><i data-lucide="scale"></i></span><span class="f-kpi-label">Still baki</span></div>
            <div class="f-kpi-value">{{ \App\Support\DemoData::taka($bakiTotal) }}</div>
            <div class="f-kpi-meta">{{ $people->count() }} people</div>
        </div>
    </div>

    <div x-data="{ q: @js(request('search', '')) }">
        <div class="f-search" style="margin-bottom:12px">
            <i data-lucide="search"></i>
            <input type="search" x-model="q" placeholder="Search person or shop" autocomplete="off" aria-label="Search people">
        </div>

        <section class="f-panel" style="margin-bottom:14px">
            <div class="f-panel-head">
                <h2><span class="f-ico tone-green"><i data-lucide="users"></i></span>By person</h2>
                <span class="f-muted f-small">{{ $people->count() }}</span>
            </div>
            <div class="f-table-wrap f-desktop-only">
                <table class="f-table">
                    <thead>
                        <tr>
                            <th class="wrap">Person</th>
                            <th class="wrap">Shop</th>
                            <th class="num">Received</th>
                            <th class="num">You collected</th>
                            <th class="num">Baki</th>
                            <th>Last paid</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($people as $row)
                            <tr data-search="{{ strtolower($row['person'].' '.$row['shop']->name.' '.$row['shop']->code) }}" x-show="!q || ($el.dataset.search || '').includes(q.toLowerCase())">
                                <td class="wrap">
                                    <span class="strong">{{ $row['person'] }}</span>
                                    <span class="sub">{{ $row['receipts'] }} receipt{{ $row['receipts'] === 1 ? '' : 's' }}</span>
                                </td>
                                <td class="wrap">{{ $row['shop']->name }}</td>
                                <td class="num strong">{{ \App\Support\DemoData::taka($row['received']) }}</td>
                                <td class="num">{{ \App\Support\DemoData::taka($row['you_collected']) }}</td>
                                <td class="num" style="{{ $row['baki'] > 0.009 ? 'color:var(--f-red);font-weight:700' : '' }}">{{ \App\Support\DemoData::taka($row['baki']) }}</td>
                                <td>{{ $row['last_at']?->format('d M Y') ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="empty">No collections yet. Money appears here after a shop pays.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="f-panel-body f-mobile-only">
                @forelse ($people as $row)
                    <div class="f-order" data-search="{{ strtolower($row['person'].' '.$row['shop']->name.' '.$row['shop']->code) }}" x-show="!q || ($el.dataset.search || '').includes(q.toLowerCase())">
                        <div class="f-order-main">
                            <div class="f-order-top">
                                <strong>{{ $row['person'] }}</strong>
                                <span class="f-order-amt">{{ \App\Support\DemoData::taka($row['received']) }}</span>
                            </div>
                            <div class="f-order-sub">
                                <span>{{ $row['shop']->name }}</span>
                                <span>
                                    @if ($row['you_collected'] > 0.009) You {{ \App\Support\DemoData::taka($row['you_collected']) }} · @endif
                                    @if ($row['baki'] > 0.009) Baki {{ \App\Support\DemoData::taka($row['baki']) }} @else Paid up @endif
                                </span>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="f-empty">
                        <i data-lucide="banknote"></i>
                        <strong>No money received yet</strong>
                        Collections from shop owners will show here.
                    </div>
                @endforelse
            </div>
        </section>
    </div>

    <section class="f-panel">
        <div class="f-panel-head">
            <h2><span class="f-ico tone-blue"><i data-lucide="receipt"></i></span>Recent receipts</h2>
        </div>
        <div class="f-table-wrap f-desktop-only">
            <table class="f-table">
                <thead><tr><th>Date</th><th class="wrap">From</th><th>Method</th><th class="num">Amount</th><th class="wrap">Collected by</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($receipts as $payment)
                        <tr>
                            <td>{{ $payment->paid_at?->format('d M Y') ?: $payment->created_at?->format('d M Y') }}</td>
                            <td class="wrap">
                                <span class="strong">{{ $payment->shop?->owner_name ?: $payment->shop?->name }}</span>
                                <span class="sub">{{ $payment->shop?->name }}</span>
                            </td>
                            <td>{{ $payment->methodLabel() }}</td>
                            <td class="num strong">{{ \App\Support\DemoData::taka($payment->amount) }}</td>
                            <td class="wrap">{{ $payment->creator?->name ?: '—' }}</td>
                            <td><span class="f-status {{ $payment->status === \App\Models\Payment::STATUS_VERIFIED ? 'green' : 'amber' }}">{{ $payment->statusLabel() }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="empty">No receipts yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="f-panel-body f-mobile-only">
            @foreach ($receipts as $payment)
                <div class="f-order">
                    <div class="f-order-main">
                        <div class="f-order-top">
                            <strong>{{ $payment->shop?->owner_name ?: $payment->shop?->name }}</strong>
                            <span class="f-order-amt">{{ \App\Support\DemoData::taka($payment->amount) }}</span>
                        </div>
                        <div class="f-order-sub">
                            <span>{{ $payment->methodLabel() }} · {{ $payment->creator?->name ?: 'Office' }}</span>
                            <span>{{ $payment->statusLabel() }}</span>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@endsection
