@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
    @php
        $user = auth()->user();
        $showAudit = $pendingAudit > 0 && $user->can('orders.approve');
        $showPayments = $pendingPayments > 0 && $user->can('payments.verify');
        $canAnalytics = $user->can('analytics.view');
        $canOrders = $user->can('orders.view');
        $quickActions = collect([
            ['Add Shop', 'store', 'shops.create', 'shops.create'],
            ['Create Order', 'shopping-bag', 'orders.create', 'orders.create'],
            ['Add Product', 'package-plus', 'products.create', 'products.create'],
            ['Add Shipment', 'ship', 'shipments.create', 'shipments.create'],
            ['Receive Stock', 'package-check', 'inventory.receive', 'inventory.receive'],
            ['Generate Report', 'file-bar-chart', 'reports.index', 'reports.view'],
            ['Targets', 'target', 'targets.index', 'targets.view'],
            ['Analytics', 'trending-up', 'analytics.index', 'analytics.view'],
        ])->filter(fn ($a) => $user->can($a[3]));
        $showAnything = $canAnalytics || $canOrders || $quickActions->isNotEmpty()
            || $user->can('products.view') || $user->can('shipments.view');

        $primaryStats = collect($stats)->take(4)->values();
        $secondaryStats = collect($stats)->slice(4)->values();
        $orderTotal = array_sum($orderStatus);
        $pipeline = [
            ['Pending', $orderStatus['pending_audit'], 'is-warn'],
            ['Approved', $orderStatus['approved'], ''],
            ['Processing', $orderStatus['processing'], ''],
            ['Dispatched', $orderStatus['dispatched'], ''],
            ['Delivered', $orderStatus['delivered'], 'is-ok'],
        ];
    @endphp

    <div class="dash">
        <header class="dash-head">
            <div>
                <div class="page-kicker"><strong>Dashboard</strong></div>
                <p class="dash-welcome">Welcome back, {{ $user?->name }}</p>
            </div>
            <div class="dash-head-meta">
                <span class="dash-period">
                    <i data-lucide="calendar"></i>
                    {{ now()->startOfMonth()->format('d M') }} – {{ now()->format('d M Y') }}
                </span>
                @if ($quickActions->isNotEmpty())
                    <div class="dash-quick">
                        @foreach ($quickActions->take(4) as $action)
                            <a class="dash-quick-link" href="{{ route($action[2]) }}">
                                <i data-lucide="{{ $action[1] }}"></i>
                                {{ $action[0] }}
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </header>

        @if ($showAudit || $showPayments)
            <div class="dash-alert">
                <div class="dash-alert-copy">
                    <strong>Needs attention</strong>
                    <span class="muted">
                        @can('invoices.view'){{ $openInvoices }} open invoice(s)@endcan
                    </span>
                </div>
                <div class="dash-alert-actions">
                    @if ($showAudit)
                        <a class="btn btn-primary" href="{{ route('orders.index', ['audit_queue' => 1]) }}">
                            Audit queue · {{ $pendingAudit }}
                        </a>
                    @endif
                    @if ($showPayments)
                        <a class="btn btn-ghost" href="{{ route('payments.index', ['status' => 'pending']) }}">
                            Payments · {{ $pendingPayments }}
                        </a>
                    @endif
                </div>
            </div>
        @endif

        @unless ($showAnything)
            <div class="card dash-empty">
                <div class="section-title">No modules assigned yet</div>
                <p class="muted">Ask a Super Admin to assign you a role with dashboard access.</p>
            </div>
        @endunless

        @if ($canAnalytics)
            <section class="dash-kpis" aria-label="Key metrics">
                @foreach ($primaryStats as $stat)
                    @php $isNewShops = ($stat['title'] ?? '') === 'New Shops (This Month)'; @endphp
                    @if ($isNewShops)
                        <a href="{{ route('shops.index') }}" class="dash-kpi">
                    @else
                        <div class="dash-kpi">
                    @endif
                        <div class="dash-kpi-label">{{ $stat['title'] }}</div>
                        <div class="dash-kpi-value">
                            @if (!empty($stat['raw']))
                                {{ number_format($stat['value']) }}
                            @else
                                {{ \App\Support\DemoData::taka($stat['value']) }}
                            @endif
                        </div>
                        <div class="dash-kpi-meta {{ !empty($stat['up']) ? 'is-up' : 'is-down' }}">
                            {{ $stat['delta'] }}
                        </div>
                    @if ($isNewShops)
                        </a>
                    @else
                        </div>
                    @endif
                @endforeach
            </section>

            @if ($secondaryStats->isNotEmpty())
                <section class="dash-secondary" aria-label="More metrics">
                    @foreach ($secondaryStats as $stat)
                        <div class="dash-secondary-item">
                            <span class="dash-secondary-label">{{ $stat['title'] }}</span>
                            <strong>
                                @if (!empty($stat['raw']))
                                    {{ number_format($stat['value']) }}
                                @else
                                    {{ \App\Support\DemoData::taka($stat['value']) }}
                                @endif
                            </strong>
                            <span class="muted">{{ $stat['delta'] }}</span>
                        </div>
                    @endforeach
                </section>
            @endif
        @endif

        @if ($canAnalytics || $canOrders)
            <section class="dash-main {{ $canAnalytics && $canOrders ? 'has-split' : '' }}">
                @if ($canAnalytics)
                    <div class="card dash-panel">
                        <div class="dash-panel-head">
                            <h2 class="section-title">Sales overview</h2>
                            <span class="muted">Last 14 days</span>
                        </div>
                        <div class="dash-chart">
                            <canvas id="salesChart" height="120"></canvas>
                        </div>
                    </div>
                @endif

                @if ($canOrders)
                    <div class="card dash-panel dash-pipeline">
                        <div class="dash-panel-head">
                            <h2 class="section-title">Order pipeline</h2>
                            <span class="dash-pipeline-total">{{ $orderTotal }}</span>
                        </div>
                        <ul class="dash-pipeline-list">
                            @foreach ($pipeline as [$label, $count, $tone])
                                <li class="{{ $tone }}">
                                    <span>{{ $label }}</span>
                                    <strong>{{ $count }}</strong>
                                    <span class="dash-pipeline-bar" style="--pct: {{ $orderTotal > 0 ? round(($count / $orderTotal) * 100) : 0 }}%"></span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </section>
        @endif

        @if ($quickActions->count() > 4)
            <section class="card dash-panel dash-more-actions">
                <div class="dash-panel-head">
                    <h2 class="section-title">More actions</h2>
                </div>
                <div class="dash-action-row">
                    @foreach ($quickActions->slice(4) as $action)
                        <a class="dash-action" href="{{ route($action[2]) }}">
                            <i data-lucide="{{ $action[1] }}"></i>
                            {{ $action[0] }}
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="dash-bottom">
            @if ($canOrders)
                <div class="card dash-panel dash-orders">
                    <div class="dash-panel-head">
                        <h2 class="section-title">Recent orders</h2>
                        <a href="{{ route('orders.index') }}" class="dash-link">View all</a>
                    </div>
                    <div class="table-wrap">
                        <table class="data">
                            <thead>
                                <tr>
                                    <th>Order</th>
                                    <th>Shop</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($recentOrders as $order)
                                    <tr>
                                        <td><a class="link" href="{{ route('orders.show', $order) }}">{{ $order->number }}</a></td>
                                        <td>{{ $order->shop?->name }}</td>
                                        <td class="dash-amount">{{ \App\Support\DemoData::taka($order->total) }}</td>
                                        <td><x-badge :status="$order->statusLabel()" /></td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="dash-table-empty">No orders yet</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            <div class="dash-side">
                @can('products.view')
                    <div class="card dash-panel">
                        <div class="dash-panel-head">
                            <h2 class="section-title">Top stock</h2>
                            <a href="{{ route('products.index') }}" class="dash-link">Catalogue</a>
                        </div>
                        <ul class="dash-list">
                            @forelse ($topProducts as $product)
                                <li>
                                    <div>
                                        <strong>{{ $product->name }}</strong>
                                        <span class="muted">{{ $product->sku }} · {{ number_format($product->stock_on_hand) }} on hand</span>
                                    </div>
                                    <span class="dash-amount">{{ \App\Support\DemoData::taka($product->wholesale_price) }}</span>
                                </li>
                            @empty
                                <li class="dash-list-empty">No products</li>
                            @endforelse
                        </ul>
                    </div>
                @endcan

                @can('shipments.view')
                    <div class="card dash-panel">
                        <div class="dash-panel-head">
                            <h2 class="section-title">Shipments</h2>
                            <a href="{{ route('shipments.index') }}" class="dash-link">View all</a>
                        </div>
                        <ul class="dash-list">
                            @forelse ($shipments as $shipment)
                                <li>
                                    <div>
                                        <a class="link" href="{{ route('shipments.show', $shipment) }}">{{ $shipment->number }}</a>
                                        <span class="muted">{{ $shipment->origin ?: '—' }}</span>
                                    </div>
                                    <x-badge :status="$shipment->statusLabel()" />
                                </li>
                            @empty
                                <li class="dash-list-empty">No shipments</li>
                            @endforelse
                        </ul>
                    </div>
                @endcan
            </div>
        </section>
    </div>
@endsection

@push('scripts')
<script>
    document.getElementById('salesChart') && new Chart(document.getElementById('salesChart'), {
        type: 'line',
        data: {
            labels: @json(collect($salesSeries)->pluck('label')),
            datasets: [{
                label: 'Sales',
                data: @json(collect($salesSeries)->pluck('value')),
                borderColor: '#334155',
                backgroundColor: 'rgba(51,65,85,0.08)',
                fill: true,
                tension: 0.35,
                pointRadius: 0,
                pointHoverRadius: 4,
                borderWidth: 2,
            }]
        },
        options: {
            plugins: { legend: { display: false } },
            scales: {
                y: {
                    ticks: { callback: v => '৳ ' + Number(v).toLocaleString('en-IN'), color: '#6B6680', font: { size: 11 } },
                    grid: { color: '#EEF0F5' },
                    border: { display: false },
                },
                x: {
                    ticks: { color: '#6B6680', font: { size: 11 } },
                    grid: { display: false },
                    border: { display: false },
                }
            }
        }
    });
</script>
@endpush
