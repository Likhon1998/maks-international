@extends('field.layouts.app')
@section('title', __('field.earnings'))
@section('heading', __('field.my_earnings'))
@section('subheading', __('field.earnings_sub', ['month' => now()->translatedFormat('F Y')]))
@php
    $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
    $toneFor = fn (string $status) => match ($status) {
        \App\Models\Commission::STATUS_PAID => 'green',
        \App\Models\Commission::STATUS_APPROVED => 'blue',
        default => 'amber',
    };
@endphp
@section('content')
    <a class="f-btn f-btn-ghost f-btn-block" style="margin-bottom:12px" href="{{ route('field.collections') }}">
        <i data-lucide="banknote"></i> {{ __('field.money_from_shops') }}
    </a>
    <div class="f-kpis five">
        <div class="f-kpi tone-violet">
            <div class="f-kpi-top"><span class="f-ico"><i data-lucide="banknote"></i></span><span class="f-kpi-label">Monthly salary</span></div>
            <div class="f-kpi-value">{{ $salary['current'] ? \App\Support\DemoData::taka($salary['current']->monthly_salary) : '—' }}</div>
            <div class="f-kpi-meta">{{ ($salary['outstanding_months'] ?? 0) }} unpaid month(s)</div>
        </div>
        <div class="f-kpi tone-green">
            <div class="f-kpi-top"><span class="f-ico"><i data-lucide="trending-up"></i></span><span class="f-kpi-label">Earned this month</span></div>
            <div class="f-kpi-value">{{ \App\Support\DemoData::taka($monthEarned) }}</div>
            <div class="f-kpi-meta"><b>{{ \App\Support\DemoData::taka($monthPending) }}</b> not paid yet</div>
        </div>
        <div class="f-kpi tone-amber">
            <div class="f-kpi-top"><span class="f-ico"><i data-lucide="hourglass"></i></span><span class="f-kpi-label">Waiting for payout</span></div>
            <div class="f-kpi-value">{{ \App\Support\DemoData::taka($awaitingPayout) }}</div>
            <div class="f-kpi-meta">All months</div>
        </div>
        <div class="f-kpi tone-blue">
            <div class="f-kpi-top"><span class="f-ico"><i data-lucide="banknote"></i></span><span class="f-kpi-label">Paid to you</span></div>
            <div class="f-kpi-value">{{ \App\Support\DemoData::taka($totalPaid) }}</div>
            <div class="f-kpi-meta">Total so far</div>
        </div>
        <div class="f-kpi tone-violet">
            <div class="f-kpi-top"><span class="f-ico"><i data-lucide="percent"></i></span><span class="f-kpi-label">Your rate</span></div>
            <div class="f-kpi-value">{{ $rule ? $pct($rule->collection_rate_percent).'%' : '—' }}</div>
            <div class="f-kpi-meta">
                @if ($rule && (float) $rule->target_bonus_percent > 0)
                    of collections · <b>+{{ $pct($rule->target_bonus_percent) }}%</b> target bonus
                @elseif ($rule)
                    of every payment collected
                @else
                    Not set up yet
                @endif
            </div>
        </div>
    </div>

    <section class="f-panel">
        <div class="f-panel-head">
            <h2><span class="f-ico tone-green"><i data-lucide="list"></i></span>{{ __('field.commission_history') }}</h2>
            <span class="f-muted f-small">{{ __('field.latest_n', ['count' => $commissions->count()]) }}</span>
        </div>
        <div class="f-table-wrap f-desktop-only">
            <table class="f-table f-table-earn">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th class="shop">Shop</th>
                        <th class="num">Base</th>
                        <th class="num">Rate</th>
                        <th class="num">Commission</th>
                        <th class="status">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($commissions as $commission)
                        <tr>
                            <td>{{ $commission->created_at?->format('d M Y') }}</td>
                            <td><span class="strong">{{ $commission->typeLabel() }}</span></td>
                            <td class="shop">{{ $commission->payment?->shop?->name ?? $commission->order?->shop?->name ?? '—' }}</td>
                            <td class="num">{{ \App\Support\DemoData::taka($commission->base_amount) }}</td>
                            <td class="num">{{ $pct($commission->rate_percent) }}%</td>
                            <td class="num strong">{{ \App\Support\DemoData::taka($commission->commission_amount) }}</td>
                            <td class="status"><span class="f-status {{ $toneFor($commission->status) }}">{{ $commission->statusLabel() }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="empty">No commission yet. It appears after the office verifies a shop payment on your orders.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="f-panel-body f-mobile-only">
            @forelse ($commissions as $commission)
                @php $shopName = $commission->payment?->shop?->name ?? $commission->order?->shop?->name; @endphp
                <div class="f-order">
                    <div class="f-order-main">
                        <div class="f-order-top">
                            <strong>{{ $commission->typeLabel() }}{{ $shopName ? ' · '.$shopName : '' }}</strong>
                            <span class="f-order-amt">{{ \App\Support\DemoData::taka($commission->commission_amount) }}</span>
                        </div>
                        <div class="f-order-sub">
                            <span>{{ $pct($commission->rate_percent) }}% of {{ \App\Support\DemoData::taka($commission->base_amount) }} · {{ $commission->created_at?->format('d M') }}</span>
                            <span class="f-status {{ $toneFor($commission->status) }}">{{ $commission->statusLabel() }}</span>
                        </div>
                    </div>
                </div>
            @empty
                <div class="f-empty">
                    <i data-lucide="wallet"></i>
                    <strong>No commission yet</strong>
                    It appears after the office verifies a shop payment on your orders.
                </div>
            @endforelse
        </div>
    </section>

    <div class="f-split">
        <section class="f-panel">
            <div class="f-panel-head">
                <h2><span class="f-ico tone-violet"><i data-lucide="banknote"></i></span>Salary payments</h2>
            </div>
            <div class="f-panel-body">
                @forelse ($salary['payments'] as $payment)
                    <div class="f-kv" style="align-items:center">
                        <span>
                            <b style="display:block">{{ $payment->periodLabel() }}</b>
                            <span class="f-muted f-small">{{ $payment->method ?: 'Salary' }} · {{ ucfirst($payment->status) }}</span>
                        </span>
                        <span>{{ \App\Support\DemoData::taka($payment->total()) }}</span>
                    </div>
                @empty
                    <div class="f-empty" style="padding:16px 8px">
                        <i data-lucide="banknote"></i>
                        <strong>No salary payments yet</strong>
                    </div>
                @endforelse
            </div>
        </section>

        <section class="f-panel">
            <div class="f-panel-head">
                <h2><span class="f-ico tone-amber"><i data-lucide="trophy"></i></span>Rewards</h2>
            </div>
            <div class="f-panel-body">
                @forelse ($rewards as $reward)
                    <div class="f-kv" style="align-items:center">
                        <span style="display:flex;gap:10px;align-items:center;color:var(--f-ink)">
                            <span class="f-av tone-amber"><i data-lucide="award"></i></span>
                            <span style="min-width:0"><b style="font-weight:700;display:block">{{ $reward->title ?: $reward->typeLabel() }}</b><span class="f-status {{ $reward->status === \App\Models\Reward::STATUS_PAID ? 'green' : 'amber' }}" style="margin-top:4px">{{ $reward->statusLabel() }}</span></span>
                        </span>
                        <span>{{ \App\Support\DemoData::taka($reward->amount) }}</span>
                    </div>
                @empty
                    <div class="f-empty" style="padding:16px 8px">
                        <i data-lucide="trophy"></i>
                        <strong>No rewards yet</strong>
                        Hit your monthly target to earn a reward.
                    </div>
                @endforelse
            </div>
        </section>
    </div>
@endsection
