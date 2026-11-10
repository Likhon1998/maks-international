@extends('layouts.app')
@section('title', $report['title'])
@section('content')
    @php
        $filters = $filters ?? ['q' => '', 'category_id' => null, 'brand_id' => null, 'flow' => 'all', 'kind' => 'all', 'status' => ''];
        $period = array_filter(
            ['from' => $from, 'to' => $to, 'q' => $filters['q'] ?? '', 'category_id' => $filters['category_id'] ?? null, 'brand_id' => $filters['brand_id'] ?? null, 'flow' => $filters['flow'] ?? 'all', 'kind' => $filters['kind'] ?? 'all', 'status' => $filters['status'] ?? ''],
            fn ($value) => $value !== null && $value !== ''
        );
        $presets = [
            'Today' => [now()->toDateString(), now()->toDateString()],
            'Yesterday' => [now()->subDay()->toDateString(), now()->subDay()->toDateString()],
            'This week' => [now()->startOfWeek()->toDateString(), now()->toDateString()],
            'This month' => [now()->startOfMonth()->toDateString(), now()->toDateString()],
            'Last month' => [now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'This year' => [now()->startOfYear()->toDateString(), now()->toDateString()],
        ];
        $formatCell = function ($cell, ?string $type, string $column) {
            if ($type === 'money' || ($type === null && is_numeric($cell) && preg_match('/sales|profit|cost|total|value|revenue|limit|outstanding|available|freight|customs|insurance|landed|amount|base/i', $column))) {
                return \App\Support\DemoData::taka($cell);
            }
            if ($type === 'percent' || ($type === null && is_numeric($cell) && str_contains(strtolower($column), '%'))) {
                return rtrim(rtrim(number_format((float) $cell, 1), '0'), '.').'%';
            }
            if ($type === 'number' || is_int($cell) || (is_numeric($cell) && ! str_contains((string) $cell, '.'))) {
                return is_numeric($cell) ? number_format((float) $cell) : $cell;
            }
            if (is_float($cell) || (is_numeric($cell) && str_contains((string) $cell, '.'))) {
                return number_format((float) $cell, 2);
            }

            return $cell;
        };
    @endphp
    <x-page-header title="{{ $report['title'] }}" subtitle="{{ $report['description'] }}">
        <a class="btn btn-ghost" href="{{ route('reports.index', $period) }}">Back</a>
        @can('reports.export')
            <a class="btn btn-ghost" href="{{ route('reports.export', ['report' => $key, 'format' => 'csv'] + $period) }}">CSV</a>
            <a class="btn btn-ghost" href="{{ route('reports.export', ['report' => $key, 'format' => 'xlsx'] + $period) }}">Excel</a>
            <a class="btn btn-primary" href="{{ route('reports.export', ['report' => $key, 'format' => 'pdf'] + $period) }}">PDF</a>
        @endcan
    </x-page-header>

    <div class="card" style="padding:14px;margin-bottom:14px">
        <form class="filters" method="get" id="report-dates">
            <label class="label" style="margin:0">From
                <input class="input" type="date" name="from" value="{{ $from }}">
            </label>
            <label class="label" style="margin:0">To
                <input class="input" type="date" name="to" value="{{ $to }}">
            </label>
            <label class="label" style="margin:0">Month
                <input class="input" type="month" value="{{ substr($from, 0, 7) }}"
                    onchange="(function (el) { var p = el.value.split('-'); if (p.length !== 2) return; var last = new Date(p[0], p[1], 0).getDate(); el.form.from.value = el.value + '-01'; el.form.to.value = el.value + '-' + String(last).padStart(2, '0'); el.form.submit(); })(this)">
            </label>
            @if ($key === 'inventory')
                <label class="label" style="margin:0">Search
                    <input class="input" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Product, category or brand">
                </label>
                <label class="label" style="margin:0">Category
                    <select class="input" name="category_id">
                        <option value="">All categories</option>
                        @foreach ($categories ?? [] as $category)
                            <option value="{{ $category->id }}" @selected((string) ($filters['category_id'] ?? '') === (string) $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="label" style="margin:0">Brand
                    <select class="input" name="brand_id">
                        <option value="">All brands</option>
                        @foreach ($brands ?? [] as $brand)
                            <option value="{{ $brand->id }}" @selected((string) ($filters['brand_id'] ?? '') === (string) $brand->id)>{{ $brand->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="label" style="margin:0">Movement
                    <select class="input" name="flow">
                        <option value="all" @selected(($filters['flow'] ?? 'all') === 'all')>Stock in &amp; stock out</option>
                        <option value="in" @selected(($filters['flow'] ?? '') === 'in')>Stock in only</option>
                        <option value="out" @selected(($filters['flow'] ?? '') === 'out')>Stock out only</option>
                    </select>
                </label>
            @endif
            @if ($key === 'returns')
                <label class="label" style="margin:0">Search
                    <input class="input" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Product, shop, customer, invoice">
                </label>
                <label class="label" style="margin:0">Type
                    <select class="input" name="kind">
                        <option value="all" @selected(($filters['kind'] ?? 'all') === 'all')>Returns &amp; warranty</option>
                        <option value="returns" @selected(($filters['kind'] ?? '') === 'returns')>Returns only</option>
                        <option value="warranty" @selected(($filters['kind'] ?? '') === 'warranty')>Warranty only</option>
                    </select>
                </label>
                <label class="label" style="margin:0">Status
                    <select class="input" name="status">
                        <option value="">All statuses</option>
                        @foreach (['pending' => 'Pending', 'approved' => 'Approved', 'repaired' => 'Repaired', 'replaced' => 'Replaced', 'refunded' => 'Refunded'] as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <button class="btn btn-primary" type="submit">Apply dates</button>
            @foreach ($presets as $label => [$presetFrom, $presetTo])
                <a class="btn btn-ghost btn-sm" href="{{ route('reports.show', ['report' => $key, 'from' => $presetFrom, 'to' => $presetTo]) }}">{{ $label }}</a>
            @endforeach
        </form>
        <p class="muted" style="margin:10px 0 0;font-size:12px">Filter by a single day, a full month, or a custom from–to range.</p>
    </div>

    @if (!empty($report['kpis']))
        <div class="order-page-summary">
            @foreach ($report['kpis'] as $kpi)
                <div>
                    <span>{{ $kpi['label'] }}</span>
                    <strong>
                        @if (($kpi['type'] ?? '') === 'money')
                            {{ \App\Support\DemoData::taka($kpi['value']) }}
                        @elseif (($kpi['type'] ?? '') === 'text')
                            {{ $kpi['value'] }}
                        @elseif (($kpi['type'] ?? '') === 'percent')
                            {{ rtrim(rtrim(number_format((float) $kpi['value'], 1), '0'), '.') }}%
                        @else
                            {{ number_format((float) $kpi['value']) }}
                        @endif
                    </strong>
                </div>
            @endforeach
        </div>
    @endif

    @if (!empty($report['cards']))
        <div class="report-people">
            @foreach ($report['cards'] as $card)
                <article class="card report-person {{ ! empty($card['highlight']) ? 'is-lead' : '' }}">
                    @if (! empty($card['badge']))
                        <span class="report-person-badge">{{ $card['badge'] }}</span>
                    @endif
                    <h3>{{ $card['title'] }}</h3>
                    <dl>
                        @foreach ($card['stats'] as $stat)
                            <div>
                                <dt>{{ $stat['label'] }}</dt>
                                <dd>
                                    @if (($stat['type'] ?? '') === 'money')
                                        {{ \App\Support\DemoData::taka($stat['value']) }}
                                    @elseif (($stat['type'] ?? '') === 'text')
                                        {{ $stat['value'] }}
                                    @elseif (($stat['type'] ?? '') === 'percent')
                                        {{ rtrim(rtrim(number_format((float) $stat['value'], 1), '0'), '.') }}%
                                    @else
                                        {{ number_format((float) $stat['value']) }}
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </article>
            @endforeach
        </div>
    @endif

    @php
        $tables = [];
        if (($report['columns'] ?? []) !== [] || ($report['rows'] ?? []) !== []) {
            $tables[] = $report;
        }
        foreach ($report['sections'] ?? [] as $section) {
            $tables[] = $section;
        }
    @endphp

    @forelse ($tables as $table)
        <div class="card" style="margin-bottom:14px">
            @if (!empty($table['title']) && $table !== $report)
                <div class="order-page-card-head" style="padding:14px 16px 0">
                    <strong>{{ $table['title'] }}</strong>
                </div>
            @endif
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            @foreach ($table['columns'] as $col)
                                @php $type = $table['column_types'][$loop->index] ?? null; @endphp
                                <th class="{{ in_array($type, ['money', 'number', 'percent'], true) ? 'num' : '' }}">{{ $col }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($table['rows'] as $row)
                            <tr>
                                @foreach ($row as $i => $cell)
                                    @php $type = $table['column_types'][$i] ?? null; @endphp
                                    <td class="{{ in_array($type, ['money', 'number', 'percent'], true) ? 'num' : '' }}">
                                        {{ $formatCell($cell, $type, $table['columns'][$i] ?? '') }}
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="{{ max(1, count($table['columns'])) }}" class="empty">No rows for this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <div class="card">
            <div class="empty" style="padding:24px;text-align:center">No rows for this period.</div>
        </div>
    @endforelse
@endsection
