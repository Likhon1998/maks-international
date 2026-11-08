@extends('layouts.app')
@section('title', 'Deliveries')
@section('content')
    <x-page-header title="Deliveries" subtitle="Assign a delivery man, then track each drop to the shop.">
        <a class="btn btn-ghost" href="{{ route('deliveries.men') }}">Delivery men</a>
        <a class="btn btn-ghost" href="{{ route('fulfilment.index') }}">Warehouse desk</a>
    </x-page-header>

    @if (session('success'))
        <div class="card" style="padding:12px 16px;margin-bottom:14px;background:#e8f8ee;color:#15803d">{{ session('success') }}</div>
    @endif

    <div class="card" style="padding:14px;margin-bottom:14px">
        <form class="filters" method="get">
            <select class="select" name="status">
                <option value="">All statuses</option>
                @foreach ([
                    'pending' => 'Pending',
                    'assigned' => 'Assigned',
                    'out_for_delivery' => 'Out for delivery',
                    'delivered' => 'Delivered',
                    'failed' => 'Failed',
                ] as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <select class="select" name="assigned_to">
                <option value="">All delivery men</option>
                @foreach ($deliveryMen as $man)
                    <option value="{{ $man->id }}" @selected((string) request('assigned_to') === (string) $man->id)>{{ $man->name }}</option>
                @endforeach
            </select>
            <label class="muted" style="display:inline-flex;align-items:center;gap:6px;font-size:13px">
                <input type="checkbox" name="unassigned" value="1" @checked(request('unassigned') === '1')>
                Unassigned only
            </label>
            <button class="btn btn-ghost" type="submit">Filter</button>
        </form>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Delivery</th>
                        <th>Order</th>
                        <th>Shop</th>
                        <th>Delivery man</th>
                        <th>Dispatched</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($deliveries as $delivery)
                        <tr>
                            <td><a class="link" href="{{ route('deliveries.show', $delivery) }}">{{ $delivery->number }}</a></td>
                            <td>{{ $delivery->order?->number }}</td>
                            <td>{{ $delivery->shop?->name }}</td>
                            <td>
                                @if ($delivery->assignee)
                                    {{ $delivery->assignee->name }}
                                @else
                                    <span class="muted">Unassigned</span>
                                @endif
                            </td>
                            <td class="muted">{{ $delivery->dispatched_at?->format('d M H:i') }}</td>
                            <td><x-badge :status="$delivery->statusLabel()" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="muted" style="padding:24px;text-align:center">No deliveries yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($deliveries->hasPages())
            <div style="padding:12px 16px">{{ $deliveries->links() }}</div>
        @endif
    </div>
@endsection
