@extends('layouts.app')
@section('title', 'Reports')
@section('content')
    @php
        $groups = [
            'sales' => 'Sales intelligence',
            'operations' => 'Operations',
        ];
        $grouped = collect($catalog)->groupBy(fn ($report) => $report['group'] ?? 'operations', true);
        $period = ['from' => $from, 'to' => $to];
    @endphp
    <x-page-header title="Reports" subtitle="Sales, profit and operational packs">
        <x-slot:description>Pick a date or range, then open a report. Sales figures use approved and delivered orders. Profit is selling price minus landed cost.</x-slot:description>
    </x-page-header>

    <form class="card" style="padding:14px;margin-bottom:16px" method="get">
        <div class="filters">
            <label class="label" style="margin:0">From
                <input class="input" type="date" name="from" value="{{ $from }}">
            </label>
            <label class="label" style="margin:0">To
                <input class="input" type="date" name="to" value="{{ $to }}">
            </label>
            <button class="btn btn-primary" type="submit">Set dates</button>
            <a class="btn btn-ghost" href="{{ route('reports.index', ['from' => now()->toDateString(), 'to' => now()->toDateString()]) }}">Today</a>
            <a class="btn btn-ghost" href="{{ route('reports.index', ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()]) }}">This month</a>
        </div>
    </form>

    @foreach ($groups as $group => $heading)
        @continue(! $grouped->has($group))
        <h2 class="section-title" style="margin:8px 0 12px">{{ $heading }}</h2>
        <div class="grid-4" style="margin-bottom:22px">
            @foreach ($grouped[$group] as $key => $report)
                <div class="card" style="padding:18px">
                    <div class="section-title">{{ $report['title'] }}</div>
                    <p class="muted">{{ $report['description'] }}</p>
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <a class="btn btn-primary" href="{{ route('reports.show', ['report' => $key] + $period) }}">Open</a>
                        @can('reports.export')
                            <a class="btn btn-ghost" href="{{ route('reports.export', ['report' => $key] + $period) }}">CSV</a>
                        @endcan
                    </div>
                </div>
            @endforeach
        </div>
    @endforeach
@endsection
