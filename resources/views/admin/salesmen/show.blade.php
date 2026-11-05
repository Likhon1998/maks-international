@extends('layouts.app')
@section('title', $salesman->name)
@section('content')
    <x-page-header title="{{ $salesman->name }}" subtitle="{{ $salesman->salesmanProfile?->employee_code }} · Field force">
        <a class="btn btn-ghost" href="{{ route('salesmen.edit', $salesman) }}">Edit</a>
        <a class="btn btn-ghost" href="{{ route('salesmen.index') }}">Back</a>
    </x-page-header>

    @if (session('success'))
        <div class="card" style="padding:12px 16px;margin-bottom:14px;background:#e8f8ee;color:#15803d">{{ session('success') }}</div>
    @endif

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:14px">
        <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">Territory</div><div style="font-weight:700">{{ $salesman->salesmanProfile?->territory?->name ?: '—' }}</div></div>
        <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">Assigned shops</div><div style="font-weight:700">{{ $salesman->assignedShops->count() }}</div></div>
        <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">Monthly target</div><div style="font-weight:700">{{ \App\Support\DemoData::taka($salesman->salesmanProfile?->monthly_target ?? 0) }}</div></div>
        <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">This month collected</div><div style="font-weight:700">{{ \App\Support\DemoData::taka($monthOrders) }}</div></div>
        <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">Grade / rating</div><div style="font-weight:700">{{ $salesman->salesmanProfile?->grade ?: '—' }} · {{ $salesman->salesmanProfile?->rating !== null ? $salesman->salesmanProfile->rating.'/5' : '—' }}</div></div>
    </div>

    @php
        $today = $activity['today'];
        $month = $activity['month'];
        $all = $activity['all'];
    @endphp
    <div class="fa-stats">
        <a class="card fa-stat fa-violet" href="{{ route('visits.index', ['period' => 'today', 'salesman_id' => $salesman->id]) }}#log">
            <div class="fa-stat-label">Shop visits today</div>
            <div class="fa-stat-value">{{ $today['visits'] }}</div>
            <div class="fa-stat-meta">{{ $today['shops'] }} different shop{{ $today['shops'] === 1 ? '' : 's' }} · {{ $today['last_at'] ? 'last '.$today['last_at']->format('g:i A') : 'no visits yet' }}</div>
        </a>
        <a class="card fa-stat fa-blue" href="{{ route('visits.index', ['period' => 'month', 'salesman_id' => $salesman->id]) }}#log">
            <div class="fa-stat-label">Visits this month</div>
            <div class="fa-stat-value">{{ $month['visits'] }}</div>
            <div class="fa-stat-meta">{{ $month['shops'] }} unique shops · {{ $all['visits'] }} all time</div>
        </a>
        <div class="card fa-stat fa-green">
            <div class="fa-stat-label">Orders this month</div>
            <div class="fa-stat-value">{{ $month['orders'] }}</div>
            <div class="fa-stat-meta">{{ \App\Support\DemoData::taka($month['order_value']) }}{{ $month['conversion'] !== null ? ' · '.$month['conversion'].'% of visits' : '' }}</div>
        </div>
        <a class="card fa-stat fa-amber" href="#added-shops">
            <div class="fa-stat-label">Shops added</div>
            <div class="fa-stat-value">{{ $all['added'] }}</div>
            <div class="fa-stat-meta">{{ $month['added'] }} this month{{ $all['added_pending'] ? ' · '.$all['added_pending'].' pending approval' : '' }}</div>
        </a>
    </div>

    <div class="card" style="padding:0;margin-bottom:14px">
        <div style="padding:14px;font-weight:700;border-bottom:1px solid #eee;display:flex;justify-content:space-between;gap:8px;align-items:center">
            <span>Delivery stock</span>
            <a class="link" href="{{ route('reports.show', ['report' => 'salesman-stock']) }}">Full report ›</a>
        </div>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th class="num">Received</th>
                        <th class="num">Delivered</th>
                        <th class="num">Returned</th>
                        <th class="num">Remaining</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($stock as $row)
                        <tr>
                            <td>{{ $row['name'] }} <span class="muted">{{ $row['sku'] }}</span></td>
                            <td class="num">{{ $row['received'] }}</td>
                            <td class="num">{{ $row['delivered'] }}</td>
                            <td class="num">{{ $row['returned'] }}</td>
                            <td class="num" style="font-weight:800">{{ $row['remaining'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted" style="padding:16px;text-align:center">No delivery stock recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($assignedDeliveries->isNotEmpty())
            <div style="padding:12px 14px;border-top:1px solid #eee">
                <div class="muted" style="font-size:12px;margin-bottom:8px">Recent assigned deliveries</div>
                <div style="display:grid;gap:6px">
                    @foreach ($assignedDeliveries as $delivery)
                        <div style="display:flex;justify-content:space-between;gap:8px;font-size:13px">
                            <a class="link" href="{{ route('deliveries.show', $delivery) }}">{{ $delivery->number }}</a>
                            <span>{{ $delivery->shop?->name }}</span>
                            <span class="muted">{{ $delivery->statusLabel() }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    <div class="card" style="padding:16px;margin-bottom:14px" x-data="{ copied: false }">
        <div style="font-weight:700;margin-bottom:8px">Field login</div>
        <div class="muted" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
            <span>URL:</span>
            <code id="salesman-login-url">{{ url('/field/login') }}</code>
            <button type="button" class="btn btn-ghost btn-sm"
                @click="navigator.clipboard.writeText('{{ url('/field/login') }}'); copied = true; setTimeout(() => copied = false, 1500)">
                <span x-text="copied ? 'Copied' : 'Copy link'"></span>
            </button>
            <a class="link" href="{{ route('salesmen.login-logs', $salesman) }}">Login activity</a>
        </div>
        <div class="muted">Email / username: {{ $salesman->email }}</div>
        <div class="muted">Status: {{ $salesman->is_active ? 'Active' : 'Inactive' }}</div>
        @can('users.reset_password')
            <form method="post" action="{{ route('salesmen.reset-password', $salesman) }}" style="margin-top:10px"
                onsubmit="return confirm('Generate a temporary password? The current password cannot be viewed.')">
                @csrf
                <button class="btn btn-ghost btn-sm" type="submit">Generate temporary password</button>
            </form>
        @endcan
        <div class="muted" style="font-size:12px;margin-top:8px">Existing passwords are hashed and cannot be viewed. Use reset to issue a temporary password.</div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div class="card">
            <div style="padding:14px;font-weight:700;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center;gap:8px">
                Assigned shops
                @can('salesmen.edit')
                    <a class="link" href="{{ route('salesmen.edit', $salesman) }}#assign-shops">Assign dokan ›</a>
                @endcan
            </div>
            <div class="table-wrap">
                <table class="data">
                    <tbody>
                        @forelse ($salesman->assignedShops as $shop)
                            <tr>
                                <td><a class="link" href="{{ route('shops.show', $shop) }}">{{ $shop->code }}</a></td>
                                <td>{{ $shop->name }}</td>
                                <td class="muted">{{ $shop->city }}</td>
                            </tr>
                        @empty
                            <tr><td class="muted" style="padding:16px">No shops assigned.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card">
            <div style="padding:14px;font-weight:700;border-bottom:1px solid #eee">Recent orders</div>
            <div class="table-wrap">
                <table class="data">
                    <tbody>
                        @forelse ($salesman->salesmanOrders as $order)
                            <tr>
                                <td><a class="link" href="{{ route('orders.show', $order) }}">{{ $order->number }}</a></td>
                                <td>{{ $order->shop?->name }}</td>
                                <td>{{ \App\Support\DemoData::taka($order->total) }}</td>
                            </tr>
                        @empty
                            <tr><td class="muted" style="padding:16px">No orders yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px">
        <div class="card">
            <div class="fa-card-head">
                <strong>Recent visits</strong>
                <a class="link" href="{{ route('visits.index', ['period' => 'all', 'salesman_id' => $salesman->id]) }}#log">All visits ›</a>
            </div>
            <div class="table-wrap">
                <table class="data">
                    <tbody>
                        @forelse ($salesman->visits as $visit)
                            <tr>
                                <td style="white-space:nowrap">{{ $visit->checked_in_at?->format('d M, g:i A') }}</td>
                                <td>{{ $visit->shop?->name }}</td>
                                <td><x-badge :status="$visit->outcomeLabel()" /></td>
                            </tr>
                        @empty
                            <tr><td class="muted" style="padding:16px">No visits yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card" id="added-shops">
            <div class="fa-card-head">
                <strong>Shops added in the field</strong>
                @if ($salesman->createdShops->isNotEmpty())
                    <a class="link" href="{{ route('shops.index', ['source' => 'field', 'search' => $salesman->name]) }}">Open in Shops ›</a>
                @endif
            </div>
            <div class="table-wrap">
                <table class="data">
                    <tbody>
                        @forelse ($salesman->createdShops as $shop)
                            <tr>
                                <td><a class="link" href="{{ route('shops.show', $shop) }}">{{ $shop->code }}</a></td>
                                <td>{{ $shop->name }}<div class="muted" style="font-size:11.5px">{{ $shop->city ?: '—' }} · {{ $shop->created_at?->format('d M Y') }}</div></td>
                                <td><x-badge :status="$shop->statusLabel()" /></td>
                            </tr>
                        @empty
                            <tr><td class="muted" style="padding:16px">This salesman hasn't added any shops yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
