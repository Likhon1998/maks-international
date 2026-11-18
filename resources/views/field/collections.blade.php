@extends('field.layouts.app')
@php
    $periodLabel = $singleDay
        ? \Illuminate\Support\Carbon::parse($from)->isToday()
            ? __('field.period_today')
            : (\Illuminate\Support\Carbon::parse($from)->isYesterday()
                ? __('field.period_yesterday')
                : \Illuminate\Support\Carbon::parse($from)->format('d M Y'))
        : \Illuminate\Support\Carbon::parse($from)->format('d M Y').' – '.\Illuminate\Support\Carbon::parse($to)->format('d M Y');

    $chips = [
        ['label' => __('field.period_today'), 'from' => today()->toDateString(), 'to' => today()->toDateString()],
        ['label' => __('field.period_yesterday'), 'from' => today()->subDay()->toDateString(), 'to' => today()->subDay()->toDateString()],
        ['label' => __('field.period_last_7'), 'from' => today()->subDays(6)->toDateString(), 'to' => today()->toDateString()],
        ['label' => __('field.period_this_month'), 'from' => now()->startOfMonth()->toDateString(), 'to' => today()->toDateString()],
        ['label' => __('field.period_last_month'), 'from' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(), 'to' => now()->subMonthNoOverflow()->endOfMonth()->toDateString()],
    ];

    $collected = (float) ($stats['collected'] ?? ($receivedTotal + $pendingTotal));
    $baki = (float) ($stats['baki'] ?? $bakiTotal);
    $pool = $collected + $baki;
    $collectedShare = $pool > 0.009 ? min(100, max(0, ($collected / $pool) * 100)) : 0;
    $collectionRate = $stats['collection_rate'] ?? null;
    $confirmedRate = $stats['confirmed_rate'] ?? null;
@endphp
@section('title', __('field.collected_title'))
@section('heading', __('field.collected_title'))
@section('subheading', __('field.collected_period_sub', ['period' => $periodLabel]))
@section('actions')
    <a class="f-btn f-btn-primary f-btn-sm" href="{{ route('field.dues') }}"><i data-lucide="scale"></i> {{ __('field.collect_baki') }}</a>
@endsection
@section('content')
    <form method="get" action="{{ route('field.collections') }}" class="f-datebar">
        @if (request('search'))
            <input type="hidden" name="search" value="{{ request('search') }}">
        @endif
        <label class="f-datebar-field">
            <span>{{ __('field.from') }}</span>
            <input class="f-input" type="date" name="from" value="{{ $from }}" max="{{ today()->toDateString() }}" aria-label="{{ __('field.from') }}">
        </label>
        <label class="f-datebar-field">
            <span>{{ __('field.to') }}</span>
            <input class="f-input" type="date" name="to" value="{{ $to }}" max="{{ today()->toDateString() }}" aria-label="{{ __('field.to') }}">
        </label>
        <button class="f-btn f-btn-primary" type="submit"><i data-lucide="search"></i> {{ __('field.apply_range') }}</button>
        @if ($filtered && ! (\Illuminate\Support\Carbon::parse($from)->isToday() && \Illuminate\Support\Carbon::parse($to)->isToday()))
            <a class="f-btn f-btn-ghost" href="{{ route('field.collections') }}">{{ __('field.period_today') }}</a>
        @endif
    </form>

    <div class="f-filters" style="margin:0 0 12px;padding:0">
        @foreach ($chips as $chip)
            <a class="f-filter {{ $from === $chip['from'] && $to === $chip['to'] ? 'active' : '' }}"
               href="{{ route('field.collections', array_filter(['from' => $chip['from'], 'to' => $chip['to'], 'search' => request('search')])) }}">{{ $chip['label'] }}</a>
        @endforeach
    </div>

    <div class="f-kpis">
        <div class="f-kpi tone-green">
            <div class="f-kpi-top"><span class="f-ico"><i data-lucide="banknote"></i></span><span class="f-kpi-label">{{ __('field.collected_in_period') }}</span></div>
            <div class="f-kpi-value">{{ \App\Support\DemoData::taka($collected) }}</div>
            <div class="f-kpi-meta">{{ __('field.verified_and_waiting', ['verified' => \App\Support\DemoData::taka($receivedTotal), 'waiting' => \App\Support\DemoData::taka($pendingTotal)]) }}</div>
        </div>
        <div class="f-kpi tone-blue">
            <div class="f-kpi-top"><span class="f-ico"><i data-lucide="coins"></i></span><span class="f-kpi-label">{{ __('field.you_collected') }}</span></div>
            <div class="f-kpi-value">{{ \App\Support\DemoData::taka($youCollected) }}</div>
            <div class="f-kpi-meta">{{ __('field.in_your_name') }} · {{ $periodLabel }}</div>
        </div>
        <div class="f-kpi tone-amber">
            <div class="f-kpi-top"><span class="f-ico"><i data-lucide="hourglass"></i></span><span class="f-kpi-label">{{ __('field.waiting_office') }}</span></div>
            <div class="f-kpi-value">{{ \App\Support\DemoData::taka($pendingTotal) }}</div>
            <div class="f-kpi-meta">{{ __('field.in_this_range') }}</div>
        </div>
        <div class="f-kpi tone-rose">
            <div class="f-kpi-top"><span class="f-ico"><i data-lucide="scale"></i></span><span class="f-kpi-label">{{ __('field.still_baki_now') }}</span></div>
            <div class="f-kpi-value">{{ \App\Support\DemoData::taka($baki) }}</div>
            <div class="f-kpi-meta">{{ __('field.open_across_shops') }}</div>
        </div>
    </div>

    <section class="f-panel f-collect-rate" style="margin-bottom:14px">
        <div class="f-panel-head">
            <h2><span class="f-ico tone-violet"><i data-lucide="percent"></i></span>{{ __('field.collection_rate') }}</h2>
            <span class="f-muted f-small">{{ $periodLabel }}</span>
        </div>
        <div class="f-panel-body">
            <div class="f-rate-head">
                <div>
                    <strong class="f-rate-value">{{ $collectionRate !== null ? number_format($collectionRate, 1).'%' : '—' }}</strong>
                    <span class="f-muted f-small">{{ __('field.collected_of_pool') }}</span>
                </div>
                @if ($confirmedRate !== null)
                    <div class="f-rate-side">
                        <strong>{{ number_format($confirmedRate, 1) }}%</strong>
                        <span class="f-muted f-small">{{ __('field.office_confirmed_share') }}</span>
                    </div>
                @endif
            </div>
            <div class="f-rate-track" role="img" aria-label="{{ __('field.collection_rate') }} {{ $collectionRate !== null ? number_format($collectionRate, 1).'%' : '' }}">
                <span class="f-rate-fill is-collected" style="width: {{ number_format($collectedShare, 2, '.', '') }}%"></span>
            </div>
            <div class="f-rate-legend">
                <span><i class="dot collected"></i>{{ __('field.collected_in_period') }} · {{ \App\Support\DemoData::taka($collected) }}</span>
                <span><i class="dot baki"></i>{{ __('field.still_baki_now') }} · {{ \App\Support\DemoData::taka($baki) }}</span>
            </div>
            <p class="f-muted f-small" style="margin:10px 0 0">{{ __('field.collection_rate_note') }}</p>
        </div>
    </section>

    <div x-data="{ q: @js(request('search', '')) }">
        <div class="f-search" style="margin-bottom:12px">
            <i data-lucide="search"></i>
            <input type="search" x-model="q" placeholder="{{ __('field.search_person_shop') }}" autocomplete="off" aria-label="{{ __('field.search_person_shop') }}">
        </div>

        <section class="f-panel" style="margin-bottom:14px">
            <div class="f-panel-head">
                <h2><span class="f-ico tone-green"><i data-lucide="users"></i></span>{{ __('field.by_person') }}</h2>
                <span class="f-muted f-small">{{ __('field.people_count', ['count' => $people->count()]) }}</span>
            </div>
            <div class="f-table-wrap f-desktop-only">
                <table class="f-table">
                    <thead>
                        <tr>
                            <th class="wrap">{{ __('field.person') }}</th>
                            <th class="wrap">{{ __('field.shop') }}</th>
                            <th class="num">{{ __('field.collected_in_period') }}</th>
                            <th class="num">{{ __('field.you_collected') }}</th>
                            <th class="num">{{ __('field.still_baki_now') }}</th>
                            <th>{{ __('field.last_paid') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($people as $row)
                            <tr data-search="{{ strtolower($row['person'].' '.$row['shop']->name.' '.$row['shop']->code) }}" x-show="!q || ($el.dataset.search || '').includes(q.toLowerCase())">
                                <td class="wrap">
                                    <span class="strong">{{ $row['person'] }}</span>
                                    <span class="sub">{{ trans_choice('field.receipts_count', $row['receipts'], ['count' => $row['receipts']]) }}</span>
                                </td>
                                <td class="wrap">{{ $row['shop']->name }}</td>
                                <td class="num strong">
                                    {{ \App\Support\DemoData::taka($row['period_collected'] ?? ($row['received'] + $row['pending'])) }}
                                    @if ($row['pending'] > 0.009)
                                        <span class="sub">{{ __('field.waiting_confirm_amount', ['amount' => number_format($row['pending'], 2)]) }}</span>
                                    @endif
                                </td>
                                <td class="num">{{ \App\Support\DemoData::taka($row['you_collected']) }}</td>
                                <td class="num" style="{{ $row['baki'] > 0.009 ? 'color:var(--f-red);font-weight:700' : '' }}">{{ \App\Support\DemoData::taka($row['baki']) }}</td>
                                <td>{{ $row['last_at']?->format('d M Y') ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="empty">{{ __('field.no_collections_in_range') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="f-panel-body f-mobile-only">
                @forelse ($people as $row)
                    @php $periodAmt = $row['period_collected'] ?? ($row['received'] + $row['pending']); @endphp
                    <div class="f-order" data-search="{{ strtolower($row['person'].' '.$row['shop']->name.' '.$row['shop']->code) }}" x-show="!q || ($el.dataset.search || '').includes(q.toLowerCase())">
                        <div class="f-order-main">
                            <div class="f-order-top">
                                <strong>{{ $row['person'] }}</strong>
                                <span class="f-order-amt">{{ \App\Support\DemoData::taka($periodAmt) }}</span>
                            </div>
                            <div class="f-order-sub">
                                <span>{{ $row['shop']->name }}</span>
                                <span>
                                    @if ($row['you_collected'] > 0.009) {{ __('field.you_amount', ['amount' => \App\Support\DemoData::taka($row['you_collected'])]) }} · @endif
                                    @if ($row['baki'] > 0.009) {{ __('field.still_baki') }} {{ \App\Support\DemoData::taka($row['baki']) }} @else {{ __('field.paid_up') }} @endif
                                </span>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="f-empty">
                        <i data-lucide="banknote"></i>
                        <strong>{{ __('field.no_money_in_range') }}</strong>
                        {{ __('field.collections_range_hint') }}
                    </div>
                @endforelse
            </div>
        </section>
    </div>

    <section class="f-panel">
        <div class="f-panel-head">
            <h2><span class="f-ico tone-blue"><i data-lucide="receipt"></i></span>{{ __('field.receipts_in_range') }}</h2>
            <span class="f-muted f-small">{{ $periodLabel }}</span>
        </div>
        <div class="f-table-wrap f-desktop-only">
            <table class="f-table">
                <thead><tr><th>{{ __('field.date') }}</th><th class="wrap">{{ __('field.from') }}</th><th>{{ __('field.method') }}</th><th class="num">{{ __('field.amount') }}</th><th class="wrap">{{ __('field.collected_by') }}</th><th>{{ __('field.status_col') }}</th></tr></thead>
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
                        <tr><td colspan="6" class="empty">{{ __('field.no_receipts_in_range') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="f-panel-body f-mobile-only">
            @forelse ($receipts as $payment)
                <div class="f-order">
                    <div class="f-order-main">
                        <div class="f-order-top">
                            <strong>{{ $payment->shop?->owner_name ?: $payment->shop?->name }}</strong>
                            <span class="f-order-amt">{{ \App\Support\DemoData::taka($payment->amount) }}</span>
                        </div>
                        <div class="f-order-sub">
                            <span>{{ $payment->paid_at?->format('d M') ?: $payment->created_at?->format('d M') }} · {{ $payment->methodLabel() }} · {{ $payment->creator?->name ?: __('field.office') }}</span>
                            <span>{{ $payment->statusLabel() }}</span>
                        </div>
                    </div>
                </div>
            @empty
                <div class="f-empty">
                    <i data-lucide="receipt"></i>
                    <strong>{{ __('field.no_receipts_in_range') }}</strong>
                </div>
            @endforelse
        </div>
    </section>
@endsection
