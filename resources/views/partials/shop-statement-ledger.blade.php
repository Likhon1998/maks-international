@php
    /** @var array<string, mixed> $summary */
    /** @var \Illuminate\Support\Collection $entries */
    $showShop = $showShop ?? false;
@endphp

<div class="stmt">
    <div class="stmt-kpis">
        @if ($showShop)
            <div class="stmt-kpi">
                <span class="stmt-kpi-label">Shop</span>
                <strong class="stmt-kpi-value stmt-kpi-text">{{ $summary['shop_name'] }}</strong>
            </div>
        @endif
        <div class="stmt-kpi">
            <span class="stmt-kpi-label">Total purchases</span>
            <strong class="stmt-kpi-value">{{ \App\Support\DemoData::taka($summary['total_purchases']) }}</strong>
        </div>
        <div class="stmt-kpi">
            <span class="stmt-kpi-label">Total payments</span>
            <strong class="stmt-kpi-value">{{ \App\Support\DemoData::taka($summary['total_payments']) }}</strong>
        </div>
        <div class="stmt-kpi {{ (float) $summary['outstanding'] > 0.009 ? 'is-debt' : 'is-clear' }}">
            <span class="stmt-kpi-label">Baki</span>
            <strong class="stmt-kpi-value">{{ \App\Support\DemoData::taka($summary['outstanding']) }}</strong>
        </div>
        <div class="stmt-kpi">
            <span class="stmt-kpi-label">Last payment</span>
            <strong class="stmt-kpi-value">{{ \App\Support\DemoData::taka($summary['last_payment_amount']) }}</strong>
            <small class="stmt-kpi-meta">{{ $summary['last_payment_date']?->format('d M Y') ?: '—' }}</small>
        </div>
    </div>

    <div class="card stmt-card">
        <div class="stmt-card-head">
            <div>
                <h2 class="stmt-card-title">Ledger</h2>
                <p class="stmt-card-sub">Purchases raise baki · payments reduce it</p>
            </div>
            <span class="stmt-card-count">{{ $entries->count() }} {{ \Illuminate\Support\Str::plural('entry', $entries->count()) }}</span>
        </div>
        <div class="table-wrap stmt-wrap">
            <table class="stmt-table">
                <colgroup>
                    <col class="stmt-col-date">
                    <col class="stmt-col-desc">
                    <col class="stmt-col-status">
                    <col class="stmt-col-money">
                    <col class="stmt-col-money">
                    <col class="stmt-col-money">
                </colgroup>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th class="num">Debit</th>
                        <th class="num">Credit</th>
                        <th class="num">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($entries as $entry)
                        @php
                            $parts = preg_split('/\s*·\s*/', (string) $entry['description'], 2) ?: [(string) $entry['description']];
                            $kind = $parts[0] ?? '';
                            $detail = $parts[1] ?? ($entry['ref'] ?? '');
                            $tone = match (true) {
                                ($entry['credit'] ?? 0) > 0.009 => 'credit',
                                ($entry['debit'] ?? 0) > 0.009 => 'debit',
                                default => '',
                            };
                        @endphp
                        <tr class="{{ $tone ? 'is-'.$tone : '' }}">
                            <td class="stmt-date">
                                <time datetime="{{ optional($entry['at'])->toDateString() }}">{{ optional($entry['at'])->format('d M Y') ?: '—' }}</time>
                            </td>
                            <td class="stmt-desc">
                                <span class="stmt-kind">{{ $kind }}</span>
                                @if ($detail !== '')
                                    <span class="stmt-ref">{{ $detail }}</span>
                                @endif
                            </td>
                            <td class="stmt-status">
                                <x-badge :status="$entry['status']" />
                            </td>
                            <td class="num stmt-money {{ ($entry['debit'] ?? 0) > 0.009 ? 'is-debit' : 'is-empty' }}">
                                {{ ($entry['debit'] ?? 0) > 0.009 ? \App\Support\DemoData::taka($entry['debit']) : '—' }}
                            </td>
                            <td class="num stmt-money {{ ($entry['credit'] ?? 0) > 0.009 ? 'is-credit' : 'is-empty' }}">
                                {{ ($entry['credit'] ?? 0) > 0.009 ? \App\Support\DemoData::taka($entry['credit']) : '—' }}
                            </td>
                            <td class="num stmt-money stmt-balance">
                                {{ \App\Support\DemoData::taka($entry['balance']) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="stmt-empty">No ledger entries yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
