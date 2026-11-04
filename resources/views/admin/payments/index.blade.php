@extends('layouts.app')
@section('title', 'Payments')
@section('content')
    <x-page-header title="Payments" subtitle="Who paid how much · receipts">
        <x-slot:description>Verified payments reduce invoice balance and shop baki.</x-slot:description>
        @can('payments.create')
            <a class="btn btn-primary" href="{{ route('payments.create') }}">Record payment</a>
        @endcan
    </x-page-header>

    @if (session('success'))
        <div class="card" style="padding:12px 16px;margin-bottom:14px;background:#e8f8ee;color:#15803d;display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between">
            <span>{{ session('success') }}</span>
            @if (session('invoice_download'))
                <a class="btn btn-primary btn-sm" href="{{ session('invoice_download') }}">Download invoice PDF</a>
            @endif
        </div>
    @endif
    @if ($errors->any())
        <div class="card" style="padding:12px 16px;margin-bottom:14px;background:#fef2f2;color:#b91c1c">{{ $errors->first() }}</div>
    @endif

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:14px">
        <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">Received</div><div style="font-weight:800">{{ \App\Support\DemoData::taka($receivedTotal) }}</div></div>
        <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">Waiting verify</div><div style="font-weight:800">{{ \App\Support\DemoData::taka($pendingTotal) }}</div></div>
        <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">Still baki</div><div style="font-weight:800;color:{{ $bakiTotal > 0.009 ? '#c2410c' : 'inherit' }}">{{ \App\Support\DemoData::taka($bakiTotal) }}</div></div>
        <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">People / shops</div><div style="font-weight:800">{{ $people->count() }}</div></div>
    </div>

    <div class="card" style="padding:12px;margin-bottom:14px">
        <form class="filters" method="get">
            <input type="hidden" name="view" value="{{ $view }}">
            <input class="input input-sm" type="search" name="search" value="{{ request('search') }}" placeholder="Person, shop, payment #" style="min-width:160px;flex:1" autocomplete="off">
            @if ($view === 'receipts')
                <select class="select select-sm" name="status" onchange="this.form.submit()">
                    <option value="">All statuses</option>
                    @foreach (['pending' => 'Pending', 'verified' => 'Verified', 'rejected' => 'Rejected'] as $value => $label)
                        <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            @endif
            <button class="btn btn-primary btn-sm" type="submit">Search</button>
        </form>
    </div>

    <div style="display:flex;gap:8px;margin-bottom:12px">
        <a class="btn btn-sm {{ $view === 'people' ? 'btn-primary' : 'btn-ghost' }}" href="{{ route('payments.index', array_filter(['view' => 'people', 'search' => request('search')])) }}">By person</a>
        <a class="btn btn-sm {{ $view === 'receipts' ? 'btn-primary' : 'btn-ghost' }}" href="{{ route('payments.index', array_filter(['view' => 'receipts', 'search' => request('search'), 'status' => request('status')])) }}">Receipts</a>
    </div>

    @if ($view === 'people')
        <div class="card">
            <div class="table-wrap">
                <table class="data data-dense">
                    <thead>
                        <tr>
                            <th>Person</th>
                            <th>Shop</th>
                            <th>Received</th>
                            <th>Waiting</th>
                            <th>Still baki</th>
                            <th>Last paid</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($people as $row)
                            <tr>
                                <td>
                                    <div style="font-weight:700">{{ $row['person'] }}</div>
                                    <div class="muted" style="font-size:11px">{{ $row['receipts'] }} receipt{{ $row['receipts'] === 1 ? '' : 's' }}</div>
                                </td>
                                <td>
                                    <a class="link" href="{{ route('shops.show', $row['shop']) }}">{{ $row['shop']->name }}</a>
                                    <div class="muted" style="font-size:11px">{{ $row['shop']->code }}</div>
                                </td>
                                <td style="font-weight:800">{{ \App\Support\DemoData::taka($row['received']) }}</td>
                                <td>{{ \App\Support\DemoData::taka($row['pending']) }}</td>
                                <td style="font-weight:700;color:{{ $row['baki'] > 0.009 ? '#c2410c' : 'inherit' }}">{{ \App\Support\DemoData::taka($row['baki']) }}</td>
                                <td class="muted">{{ $row['last_at']?->format('d M Y') ?: '—' }}</td>
                                <td><a class="link" href="{{ route('shops.statement', $row['shop']) }}">Statement</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="muted" style="padding:24px;text-align:center">No money received yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="card">
            <div class="table-wrap">
                <table class="data data-dense">
                    <thead>
                        <tr>
                            <th>Payment</th>
                            <th>From</th>
                            <th>Invoice</th>
                            <th>Method</th>
                            <th>Amount</th>
                            <th>Collected by</th>
                            <th>Paid</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($payments as $payment)
                            <tr>
                                <td>
                                    <div style="font-weight:600">{{ $payment->number }}</div>
                                    @if ($payment->reference)<div class="muted" style="font-size:11px">{{ $payment->reference }}</div>@endif
                                </td>
                                <td>
                                    <div style="font-weight:600">{{ $payment->shop?->owner_name ?: $payment->shop?->name }}</div>
                                    <div class="muted" style="font-size:11px">{{ $payment->shop?->name }}</div>
                                </td>
                                <td class="muted">
                                    @if ($payment->invoice)
                                        <a class="link" href="{{ route('invoices.show', $payment->invoice) }}">{{ $payment->invoice->number }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ $payment->methodLabel() }}</td>
                                <td style="font-weight:700">{{ \App\Support\DemoData::taka($payment->amount) }}</td>
                                <td>{{ $payment->creator?->name ?: '—' }}</td>
                                <td class="muted">{{ $payment->paid_at?->format('d M Y') }}</td>
                                <td><x-badge :status="$payment->statusLabel()" /></td>
                                <td class="actions">
                                    @if ($payment->status === 'pending')
                                        @can('payments.verify')
                                            <x-row-menu>
                                                <form method="post" action="{{ route('payments.verify', $payment) }}">
                                                    @csrf
                                                    <button class="row-menu-item" type="submit">Verify</button>
                                                </form>
                                                <div class="row-menu-reject" @click.stop>
                                                    <form method="post" action="{{ route('payments.reject', $payment) }}">
                                                        @csrf
                                                        <input class="input" name="rejection_reason" required placeholder="Reject reason">
                                                        <button class="row-menu-item is-danger" type="submit">Confirm reject</button>
                                                    </form>
                                                </div>
                                            </x-row-menu>
                                        @endcan
                                    @elseif ($payment->verifier)
                                        <span class="muted" style="font-size:11px">by {{ $payment->verifier->name }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="muted" style="padding:24px;text-align:center">No payments yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($payments->hasPages())
                <div style="padding:12px 16px">{{ $payments->links() }}</div>
            @endif
        </div>
    @endif
@endsection
