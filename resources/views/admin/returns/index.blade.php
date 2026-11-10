@extends('layouts.app')
@section('title', 'Returns & warranty')
@section('content')
    <x-page-header title="Returns & warranty" subtitle="Track customer/shop returns, warranty claims, refunds, replacements and restock" action="{{ route('returns.create') }}" action-label="New return">
        <x-slot:description>Pending claims do not change warehouse stock. Approve to issue credit, then add goods back as Good, Damaged, or Repair.</x-slot:description>
        @can('reports.view')
            <a class="btn btn-ghost" href="{{ route('reports.show', ['report' => 'returns']) }}">Return rate report</a>
        @endcan
    </x-page-header>

    @if (session('success'))
        <div class="card" style="padding:12px 16px;margin-bottom:14px;background:#e8f8ee;color:#15803d">{{ session('success') }}</div>
    @endif

    <form method="get" action="{{ route('returns.index') }}" class="card" style="padding:14px;margin-bottom:14px;display:flex;flex-wrap:wrap;gap:10px;align-items:end">
        <label class="label" style="margin:0;flex:1;min-width:220px">Search
            <input class="input" type="search" name="q" value="{{ $search ?? '' }}" placeholder="Product, customer, shop, invoice">
        </label>
        <input type="hidden" name="kind" value="{{ $kind ?? '' }}">
        <input type="hidden" name="status" value="{{ $status ?? '' }}">
        <button class="btn btn-primary" type="submit">Search</button>
        <a class="btn btn-ghost" href="{{ route('returns.index') }}">Clear</a>
    </form>

    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px">
        @foreach ([
            '' => ['All', $counts['all']],
            'returns' => ['Returns', $counts['returns']],
            'warranty' => ['Warranty', $counts['warranty']],
        ] as $key => [$label, $count])
            <a class="btn {{ ($kind ?? '') === $key ? 'btn-primary' : 'btn-ghost' }} btn-sm" href="{{ route('returns.index', array_filter(['kind' => $key ?: null, 'status' => $status ?: null, 'q' => $search ?: null])) }}">
                {{ $label }} <strong style="margin-left:4px">{{ $count }}</strong>
            </a>
        @endforeach
    </div>

    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px">
        @foreach ([
            '' => ['All statuses', $counts['all']],
            'pending' => ['Pending', $counts['pending']],
            'approved' => ['Approved', $counts['approved']],
            'repaired' => ['Repaired', $counts['repaired']],
            'replaced' => ['Replaced', $counts['replaced']],
            'refunded' => ['Refunded', $counts['refunded']],
            'restocked' => ['Added to Inventory', $counts['restocked']],
            'rejected' => ['Rejected', $counts['rejected']],
        ] as $key => [$label, $count])
            <a class="btn {{ ($status ?? '') === $key ? 'btn-primary' : 'btn-ghost' }} btn-sm" href="{{ route('returns.index', array_filter(['status' => $key ?: null, 'kind' => $kind ?: null, 'q' => $search ?: null])) }}">
                {{ $label }} <strong style="margin-left:4px">{{ $count }}</strong>
            </a>
        @endforeach
    </div>

    <div class="card">
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Return</th>
                        <th>Date</th>
                        <th>Shop / customer</th>
                        <th>Invoice</th>
                        <th>Products</th>
                        <th>Qty</th>
                        <th>Reason</th>
                        <th>Refund</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($returns as $return)
                        <tr>
                            <td><a class="link" href="{{ route('returns.show', $return) }}">{{ $return->number }}</a></td>
                            <td class="muted">{{ $return->created_at?->format('d M Y H:i') }}</td>
                            <td>{{ $return->shop?->name }}</td>
                            <td class="muted">{{ $return->invoice?->number ?: ($return->order?->number ?: '—') }}</td>
                            <td style="max-width:240px">{{ $return->productSummary() }}</td>
                            <td style="font-weight:700">{{ $return->totalQuantity() }}</td>
                            <td>{{ $return->reasonTypeLabel() }}</td>
                            <td style="font-weight:700">{{ \App\Support\DemoData::taka($return->refund_amount ?: ($return->credit_issued ? $return->total : 0)) }}</td>
                            <td><x-badge :status="$return->handlingStatusLabel()" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="muted" style="padding:24px;text-align:center">No returns in this filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($returns->hasPages())
            <div style="padding:12px 16px">{{ $returns->links() }}</div>
        @endif
    </div>
@endsection
