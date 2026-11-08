@extends('layouts.app')
@section('title', 'Due Management')
@section('content')
    <x-page-header title="Due Management" subtitle="Live outstanding balances by shop — synced from open invoices and verified payments">
        @can('dues.export')
            <a class="btn btn-ghost" href="{{ route('dues.export', array_merge(request()->query(), ['format' => 'csv'])) }}">Excel / CSV</a>
            <a class="btn btn-ghost" href="{{ route('dues.export', array_merge(request()->query(), ['format' => 'pdf'])) }}">PDF</a>
        @endcan
        @can('payments.create')
            <a class="btn btn-primary" href="{{ route('payments.create') }}">Record payment</a>
        @endcan
    </x-page-header>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:14px">
        <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">Total outstanding</div><div style="font-weight:700">{{ \App\Support\DemoData::taka($totalOutstanding) }}</div></div>
        <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">Shops with dues</div><div style="font-weight:700">{{ $rows->count() }}</div></div>
        <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">Open invoices</div><div style="font-weight:700">{{ $rows->sum('open_invoice_count') }}</div></div>
        <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">Salesmen with dues</div><div style="font-weight:700">{{ $bySalesman->count() }}</div></div>
    </div>

    <div class="card" style="padding:14px;margin-bottom:14px">
        <form class="filters" method="get" style="display:flex;flex-wrap:wrap;gap:10px;align-items:end">
            <input class="input" name="shop" value="{{ $filters['shop'] ?? '' }}" placeholder="Shop name / code">
            <select class="select" name="salesman_id">
                <option value="">All salesmen</option>
                @foreach ($salesmen as $salesman)
                    <option value="{{ $salesman->id }}" @selected(($filters['salesman_id'] ?? '') == $salesman->id)>{{ $salesman->name }}</option>
                @endforeach
            </select>
            <input class="input" type="number" step="0.01" name="min_outstanding" value="{{ $filters['min_outstanding'] ?? '' }}" placeholder="Min outstanding">
            <div>
                <div class="muted" style="font-size:11px;margin-bottom:4px">Activity from</div>
                <input class="input" type="date" name="from" value="{{ $filters['from'] ?? '' }}">
            </div>
            <div>
                <div class="muted" style="font-size:11px;margin-bottom:4px">Activity to</div>
                <input class="input" type="date" name="to" value="{{ $filters['to'] ?? '' }}">
            </div>
            <button class="btn btn-primary" type="submit">Filter</button>
            @if (array_filter($filters))
                <a class="btn btn-ghost" href="{{ route('dues.index') }}">Clear</a>
            @endif
        </form>
        <p class="muted" style="font-size:12px;margin:10px 0 0">Dates filter shops with sales or collections in that period. Outstanding is the shop’s current open balance.</p>
    </div>

    <div style="display:grid;grid-template-columns:2fr 1fr;gap:14px;margin-bottom:14px">
        <div class="card">
            <div style="padding:14px;font-weight:700;border-bottom:1px solid #eee">Outstanding shops</div>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Shop</th><th>Salesman</th><th>Outstanding</th><th>Open invoices</th><th>Last payment</th><th>Days</th><th>Sales</th><th>Collected</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr>
                                <td><a class="link" href="{{ route('shops.show', $row['shop']) }}">{{ $row['shop']->name }}</a><div class="muted" style="font-size:11px">{{ $row['shop']->code }}</div></td>
                                <td>{{ $row['salesman']?->name ?: '—' }}</td>
                                <td style="font-weight:700">{{ \App\Support\DemoData::taka($row['outstanding']) }}</td>
                                <td>
                                    {{ $row['open_invoice_count'] }}
                                    @if ($row['open_invoice_count'] > 0)
                                        <div class="muted" style="font-size:11px">{{ \App\Support\DemoData::taka($row['open_invoice_balance']) }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if ($row['last_payment_date'])
                                        {{ \App\Support\DemoData::taka($row['last_payment_amount']) }}
                                        <div class="muted" style="font-size:11px">{{ $row['last_payment_date']->format('d M Y') }}</div>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ $row['days_outstanding'] }}</td>
                                <td>{{ \App\Support\DemoData::taka($row['total_sales']) }}</td>
                                <td>{{ \App\Support\DemoData::taka($row['total_collections']) }}</td>
                                <td style="white-space:nowrap">
                                    <a class="link" href="{{ route('shops.statement', $row['shop']) }}">Statement</a>
                                    @can('payments.create')
                                        · <a class="link" href="{{ route('payments.create', ['shop_id' => $row['shop']->id]) }}">Collect</a>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="muted" style="padding:16px">No outstanding balances. Fully paid shops are hidden.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div>
            <div class="card" style="margin-bottom:14px">
                <div style="padding:14px;font-weight:700;border-bottom:1px solid #eee">By salesman</div>
                <div class="table-wrap">
                    <table class="data">
                        <tbody>
                            @forelse ($bySalesman as $row)
                                <tr>
                                    <td>{{ $row['salesman']?->name ?: 'Unassigned' }}</td>
                                    <td>{{ $row['shops'] }} shops</td>
                                    <td style="font-weight:700">{{ \App\Support\DemoData::taka($row['outstanding']) }}</td>
                                </tr>
                            @empty
                                <tr><td class="muted" style="padding:16px">No dues.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card">
                <div style="padding:14px;font-weight:700;border-bottom:1px solid #eee">Highest outstanding</div>
                <div class="table-wrap">
                    <table class="data">
                        <tbody>
                            @foreach ($highest as $row)
                                <tr>
                                    <td>{{ $row['shop']->name }}</td>
                                    <td style="font-weight:700">{{ \App\Support\DemoData::taka($row['outstanding']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
