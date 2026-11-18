@extends('field.layouts.app')
@section('title', __('field.collect_baki_title'))
@section('heading', __('field.collect_baki_title'))
@section('subheading', __('field.collect_baki_sub'))
@section('actions')
    <a class="f-btn f-btn-ghost f-btn-sm" href="{{ route('field.collections') }}"><i data-lucide="receipt"></i> {{ __('field.collected') }}</a>
@endsection
@section('content')
    <div class="f-kpis">
        <div class="f-kpi tone-rose">
            <div class="f-kpi-top"><span class="f-ico"><i data-lucide="scale"></i></span><span class="f-kpi-label">{{ __('field.total_baki') }}</span></div>
            <div class="f-kpi-value">{{ \App\Support\DemoData::taka($bakiTotal) }}</div>
            <div class="f-kpi-meta">{{ trans_choice('field.shop_count', $shopCount, ['count' => $shopCount]) }}</div>
        </div>
        <div class="f-kpi tone-green">
            <div class="f-kpi-top"><span class="f-ico"><i data-lucide="banknote"></i></span><span class="f-kpi-label">{{ __('field.ready_to_collect') }}</span></div>
            <div class="f-kpi-value">{{ \App\Support\DemoData::taka($collectableTotal) }}</div>
            <div class="f-kpi-meta">{{ __('field.delivered_orders_only') }}</div>
        </div>
    </div>

    <div x-data="{ q: @js(request('search', '')) }">
        <form class="f-search" style="margin-bottom:12px" method="get" action="{{ route('field.dues') }}" @submit.prevent="window.location = '{{ route('field.dues') }}?search=' + encodeURIComponent(q)">
            <i data-lucide="search"></i>
            <input type="search" name="search" x-model="q" placeholder="{{ __('field.search_shops_baki') }}" autocomplete="off" aria-label="{{ __('field.search_shops_baki') }}">
        </form>

        <section class="f-panel">
            <div class="f-panel-head">
                <h2><span class="f-ico tone-rose"><i data-lucide="store"></i></span>{{ __('field.shops_with_baki') }}</h2>
                <span class="f-muted f-small">{{ $shopCount }}</span>
            </div>
            <div class="f-panel-body">
                @forelse ($dues as $row)
                    <a class="f-order" href="{{ route('field.dues.shop', $row['shop']) }}"
                       data-search="{{ strtolower($row['person'].' '.$row['shop']->name.' '.$row['shop']->code.' '.($row['shop']->phone ?? '')) }}"
                       x-show="!q || ($el.dataset.search || '').includes(q.toLowerCase())">
                        <div class="f-order-main">
                            <div class="f-order-top">
                                <strong>{{ $row['shop']->name }}</strong>
                                <span class="f-order-amt" style="color:var(--f-red)">{{ \App\Support\DemoData::taka($row['baki']) }}</span>
                            </div>
                            <div class="f-order-sub">
                                <span>{{ $row['person'] }}@if($row['shop']->phone) · {{ $row['shop']->phone }}@endif</span>
                                <span>
                                    {{ trans_choice('field.open_bills', $row['order_count'], ['count' => $row['order_count']]) }}
                                    @if ($row['collectable'] > 0.009)
                                        · {{ __('field.collect_amount', ['amount' => \App\Support\DemoData::taka($row['collectable'])]) }}
                                    @endif
                                </span>
                            </div>
                        </div>
                        <i data-lucide="chevron-right" style="width:18px;height:18px;opacity:.45;flex-shrink:0"></i>
                    </a>
                @empty
                    <div class="f-empty">
                        <i data-lucide="check-circle-2"></i>
                        <strong>{{ __('field.no_baki_now') }}</strong>
                        {{ __('field.no_baki_hint') }}
                    </div>
                @endforelse
            </div>
        </section>
    </div>
@endsection
