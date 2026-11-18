@extends('field.layouts.app')
@section('title', __('field.baki').' — '.$shop->name)
@section('heading', $shop->name)
@section('subheading', ($person ?: $shop->name).($shop->phone ? ' · '.$shop->phone : ''))
@section('back', route('field.dues'))
@section('actions')
    <a class="f-btn f-btn-ghost f-btn-sm" href="{{ route('field.dues') }}"><i data-lucide="arrow-left"></i> {{ __('field.all_baki') }}</a>
@endsection
@section('content')
    <div class="f-kpis">
        <div class="f-kpi tone-rose">
            <div class="f-kpi-top"><span class="f-ico"><i data-lucide="scale"></i></span><span class="f-kpi-label">{{ __('field.shop_baki') }}</span></div>
            <div class="f-kpi-value">{{ \App\Support\DemoData::taka($baki) }}</div>
            <div class="f-kpi-meta">{{ trans_choice('field.open_orders', $order_count, ['count' => $order_count]) }}</div>
        </div>
        <div class="f-kpi tone-green">
            <div class="f-kpi-top"><span class="f-ico"><i data-lucide="banknote"></i></span><span class="f-kpi-label">{{ __('field.collect_now') }}</span></div>
            <div class="f-kpi-value">{{ \App\Support\DemoData::taka($collectable) }}</div>
            <div class="f-kpi-meta">{{ __('field.delivered_unpaid') }}</div>
        </div>
    </div>

    <section class="f-panel">
        <div class="f-panel-head">
            <h2><span class="f-ico tone-amber"><i data-lucide="receipt-text"></i></span>{{ __('field.why_this_baki') }}</h2>
            <a class="f-link" href="{{ route('field.dues') }}">{{ __('field.close') }}</a>
        </div>
        <div class="f-panel-body f-baki-list">
            @forelse ($orders as $row)
                @php
                    $order = $row['order'];
                    $due = (float) $row['due'];
                    $remaining = (float) ($row['remaining'] ?? $due);
                    $pending = (float) ($row['pending'] ?? 0);
                    $canCollect = $row['can_collect'];
                @endphp
                <article class="f-baki-card {{ $canCollect ? 'is-collect' : 'is-wait' }}">
                    <header class="f-baki-card-head">
                        <div class="f-baki-card-title">
                            <a href="{{ route('field.orders.show', $order) }}">{{ $order->number }}</a>
                            <span class="f-baki-card-meta">
                                {{ $order->statusLabel() }}
                                @if ($order->submitted_at)
                                    · {{ $order->submitted_at->format('d M Y') }}
                                @endif
                                @if ($pending > 0.009)
                                    · {{ __('field.waiting_confirm_amount', ['amount' => \App\Support\DemoData::taka($pending)]) }}
                                @endif
                            </span>
                        </div>
                        <div class="f-baki-card-due">
                            <small>{{ $canCollect ? __('field.collect') : __('field.rest') }}</small>
                            <strong>{{ \App\Support\DemoData::taka($canCollect ? $due : $remaining) }}</strong>
                        </div>
                    </header>

                    @if ($canCollect)
                        <form class="f-baki-form" method="post" action="{{ route('field.orders.collect', $order) }}"
                              x-data="{
                                  due: {{ json_encode($due) }},
                                  paidNow: {{ json_encode((float) old('amount', $due)) }},
                                  payMethod: {{ json_encode(old('method', 'cash')) }},
                                  labels: {
                                      enter: {{ json_encode(__('field.enter_amount')) }},
                                      all: {{ json_encode(__('field.collect_all')) }},
                                      collect: {{ json_encode(__('field.collect')) }}
                                  },
                                  taka(n) { return '৳ ' + Math.round(Number(n) || 0).toLocaleString('en-IN'); },
                                  left() { return Math.max(0, Math.round((this.due - Number(this.paidNow || 0)) * 100) / 100); }
                              }">
                            @csrf
                            <input type="hidden" name="redirect" value="dues">

                            <div class="f-pay-seg" role="group" aria-label="{{ __('field.collect') }}">
                                <button type="button" :class="Number(paidNow) === Math.round(due) ? 'active' : ''" @click="paidNow = Math.round(due)">{{ __('field.pay_all') }}</button>
                                <button type="button" :class="due > 0 && Number(paidNow) === Math.round(due / 2) ? 'active' : ''" @click="paidNow = Math.round(due / 2)">{{ __('field.half') }}</button>
                                <button type="button" :class="Number(paidNow) === 0 ? 'active' : ''" @click="paidNow = 0">{{ __('field.clear') }}</button>
                            </div>

                            <label class="f-baki-amount">
                                <span class="f-baki-amount-prefix">৳</span>
                                <input type="number" inputmode="decimal" min="0" step="1" name="amount"
                                       x-model.number="paidNow" :max="Math.round(due)" placeholder="0" aria-label="{{ __('field.amount') }}">
                            </label>

                            <div class="f-baki-left">
                                <span>{{ __('field.left_on_order') }}</span>
                                <b x-text="taka(left())"></b>
                            </div>

                            <div class="f-baki-method" x-show="Number(paidNow) > 0" x-cloak>
                                <select class="f-input" name="method" x-model="payMethod" aria-label="{{ __('field.method') }}">
                                    <option value="cash">{{ __('field.cash') }}</option>
                                    <option value="mobile_banking">{{ __('field.mobile_banking') }}</option>
                                    <option value="bank_transfer">{{ __('field.bank_transfer') }}</option>
                                    <option value="cheque">{{ __('field.cheque') }}</option>
                                </select>
                                <input class="f-input" type="text" name="reference" value="{{ old('reference') }}" maxlength="120" placeholder="{{ __('field.reference_optional') }}">
                            </div>

                            <button class="f-btn f-btn-primary f-btn-block" type="submit"
                                    :disabled="Number(paidNow) <= 0 || Number(paidNow) > due + 0.01">
                                <span x-text="Number(paidNow) <= 0 ? labels.enter : (left() > 0.009 ? labels.collect + ' ' + taka(paidNow) : labels.all)"></span>
                            </button>
                            <a class="f-btn f-btn-ghost f-btn-block" href="{{ route('field.dues') }}">{{ __('field.cancel_collect') }}</a>
                        </form>
                    @elseif ($pending > 0.009)
                        <p class="f-baki-wait">
                            {{ __('field.collected_waiting_office', ['amount' => \App\Support\DemoData::taka($pending)]) }}
                            {{ __('field.ledger_rest', ['amount' => \App\Support\DemoData::taka($remaining)]) }}
                            <a href="{{ route('field.orders.show', $order) }}">{{ __('field.open_order') }}</a>
                        </p>
                    @else
                        <p class="f-baki-wait">
                            {{ __('field.not_collectable_yet') }}
                            <a href="{{ route('field.orders.show', $order) }}">{{ __('field.open_order') }}</a>
                        </p>
                    @endif
                </article>
            @empty
                <div class="f-empty">
                    <i data-lucide="check-circle-2"></i>
                    <strong>{{ __('field.no_open_bills') }}</strong>
                    @if ($baki > 0.009)
                        {{ __('field.ledger_check', ['amount' => \App\Support\DemoData::taka($baki)]) }}
                    @else
                        {{ __('field.shop_clear') }}
                    @endif
                </div>
            @endforelse
        </div>
    </section>
@endsection
