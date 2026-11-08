@extends('layouts.app')
@section('title', 'Returns')
@section('content')
    <x-page-header title="Product Returns" subtitle="Unsold / rejected goods awaiting verification before inventory restock" action="{{ route('returns.create') }}" action-label="New return">
        <x-slot:description>Pending returns do not change warehouse stock. Approve, then add to inventory.</x-slot:description>
    </x-page-header>

    @if (session('success'))
        <div class="card" style="padding:12px 16px;margin-bottom:14px;background:#e8f8ee;color:#15803d">{{ session('success') }}</div>
    @endif

    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px">
        @foreach ([
            '' => ['All', $counts['all']],
            'pending' => ['Pending Return', $counts['pending']],
            'approved' => ['Approved', $counts['approved']],
            'restocked' => ['Added to Inventory', $counts['restocked']],
            'rejected' => ['Rejected', $counts['rejected']],
        ] as $key => [$label, $count])
            <a class="btn {{ ($status ?? '') === $key ? 'btn-primary' : 'btn-ghost' }} btn-sm" href="{{ route('returns.index', array_filter(['status' => $key ?: null])) }}">
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
                        <th>Shop</th>
                        <th>Salesman</th>
                        <th>Products</th>
                        <th>Qty</th>
                        <th>Reason</th>
                        <th>Total</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($returns as $return)
                        <tr>
                            <td><a class="link" href="{{ route('returns.show', $return) }}">{{ $return->number }}</a></td>
                            <td class="muted">{{ $return->created_at?->format('d M Y H:i') }}</td>
                            <td>{{ $return->shop?->name }}</td>
                            <td>{{ $return->order?->salesman?->name ?: ($return->creator?->name ?: '—') }}</td>
                            <td style="max-width:240px">{{ $return->productSummary() }}</td>
                            <td style="font-weight:700">{{ $return->totalQuantity() }}</td>
                            <td>{{ $return->reasonTypeLabel() }}</td>
                            <td style="font-weight:700">{{ \App\Support\DemoData::taka($return->total) }}</td>
                            <td><x-badge :status="$return->statusLabel()" /></td>
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
