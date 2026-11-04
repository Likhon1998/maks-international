@extends('layouts.app')
@section('title', 'Orders')
@section('content')
    @php
        $statusFlash = session('status_flash');
    @endphp
    <div
        class="orders-desk"
        x-data="orderBoard(@js($rows), @js($initialFilters), @js($statusFlash))"
    >
        <x-page-header title="Orders" subtitle="Audit · approve · reserve" action="{{ route('orders.create') }}" action-label="Create Order">
            @if ($pendingCount > 0)
                <button type="button" class="btn btn-primary btn-sm" @click="setAuditQueue()">Audit queue ({{ $pendingCount }})</button>
            @endif
        </x-page-header>

        @if (session('success'))
            <div class="status-flash status-flash--{{ $statusFlash['result'] ?? 'updated' }}" role="status">
                <div class="status-flash-main">{{ session('success') }}</div>
                @if (!empty($statusFlash['status']))
                    <div class="status-flash-meta">
                        New status
                        <span class="badge badge-{{ ($statusFlash['result'] ?? '') === 'rejected' ? 'out' : (($statusFlash['result'] ?? '') === 'approved' ? 'approved' : 'hold') }}">
                            {{ $statusFlash['status'] }}
                        </span>
                    </div>
                @endif
                @if (session('invoice_download'))
                    <div class="status-flash-meta" style="margin-top:8px">
                        <a class="btn btn-primary btn-sm" href="{{ session('invoice_download') }}">Download invoice PDF</a>
                        <span class="muted" style="font-size:12px;margin-left:8px">Includes payment history</span>
                    </div>
                @endif
            </div>
        @endif
        @if ($errors->any())
            <div class="card orders-desk-flash is-error">
                <ul style="margin:0;padding-left:18px">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <div class="card orders-desk-filters">
            <div class="filters">
                <input
                    class="input input-sm"
                    type="search"
                    x-model="search"
                    @input="syncUrl()"
                    placeholder="Order # or shop"
                    autocomplete="off"
                    style="min-width:180px;flex:1"
                >
                <select class="select select-sm" x-model="status" @change="syncUrl()">
                    <option value="">All statuses</option>
                    <option value="pending_audit">Pending Super Admin Audit</option>
                    <option value="awaiting_advance">Awaiting Advance</option>
                    <option value="approved">Approved</option>
                    <option value="delivered">Delivered</option>
                    <option value="rejected">Rejected</option>
                    <option value="cancelled">Cancelled</option>
                </select>
                <select class="select select-sm" x-model="source" @change="syncUrl()">
                    <option value="">All sources</option>
                    <option value="salesman">Salesman</option>
                    <option value="shop_portal">Shop Portal</option>
                    <option value="admin">Admin</option>
                </select>
                <button type="button" class="btn btn-ghost btn-sm" x-show="search || status || source" x-cloak @click="clearFilters()">Clear</button>
                <span class="muted" style="font-size:11px;margin-left:auto" x-text="filteredCountLabel()"></span>
            </div>
        </div>

        <div class="card orders-desk-table">
            <div class="table-wrap">
                <table class="data data-dense">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Shop</th>
                            <th class="col-hide-md">Shopkeeper</th>
                            <th class="col-hide-lg">Source</th>
                            <th class="col-hide-md">Salesman</th>
                            <th class="col-hide-lg">Lines</th>
                            <th>Total</th>
                            <th class="col-hide-lg">Submitted</th>
                            <th>Status</th>
                            <th class="actions"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="row in filteredRows()" :key="row.id">
                            <tr :class="{ 'order-row-flash': flashId === row.id }">
                                <td style="font-weight:700" x-text="row.number"></td>
                                <td x-text="row.shop"></td>
                                <td class="col-hide-md" x-text="row.shopkeeper"></td>
                                <td class="muted col-hide-lg" x-text="row.source_label"></td>
                                <td class="col-hide-md" x-text="row.salesman"></td>
                                <td class="col-hide-lg" x-text="row.item_count"></td>
                                <td style="font-weight:700">
                                    <span x-text="row.total"></span>
                                    <div class="muted" style="font-size:10px;font-weight:600" x-show="row.price_changed">Price changed</div>
                                </td>
                                <td class="muted col-hide-lg" x-text="row.submitted_at"></td>
                                <td>
                                    <span class="badge" :class="row.status_badge" x-text="row.status_label"></span>
                                </td>
                                <td class="actions">
                                    <div class="row-menu" x-data="{ open: false }" @keydown.escape.window="open = false">
                                        <button type="button" class="row-menu-btn" @click="open = !open" :aria-expanded="open.toString()" aria-haspopup="menu" title="Actions">
                                            <span aria-hidden="true">⋯</span>
                                        </button>
                                        <div class="row-menu-panel" role="menu" x-cloak x-show="open" @click.outside="open = false" @click="open = false">
                                            <a class="row-menu-item" :href="row.show_url">View order</a>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="filteredRows().length === 0">
                            <td colspan="10" class="muted" style="padding:16px;text-align:center">No orders match these filters.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
function orderBoard(rows, initialFilters, statusFlash) {
    return {
        rows: rows || [],
        search: initialFilters?.search || '',
        status: initialFilters?.status || '',
        source: initialFilters?.source || '',
        flashId: statusFlash?.order_id ? parseInt(statusFlash.order_id, 10) : null,
        init() {
            if (this.flashId) {
                this.$nextTick(() => {
                    document.querySelector('.order-row-flash')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                });
            }
        },
        filteredRows() {
            const q = (this.search || '').trim().toLowerCase();
            return this.rows.filter((row) => {
                if (this.status && row.status !== this.status) {
                    if (this.status === 'pending_audit' && row.status === 'awaiting_advance') return true;
                    if (this.status === 'approved' && ['approved', 'picking', 'picked', 'packed', 'dispatched'].includes(row.status)) return true;
                    return false;
                }
                if (this.source && row.source !== this.source) return false;
                if (!q) return true;
                return (
                    String(row.number).toLowerCase().includes(q) ||
                    String(row.shop).toLowerCase().includes(q) ||
                    String(row.shop_code).toLowerCase().includes(q) ||
                    String(row.salesman).toLowerCase().includes(q)
                );
            });
        },
        filteredCountLabel() {
            const n = this.filteredRows().length;
            const total = this.rows.length;
            return n === total ? n + ' order' + (n === 1 ? '' : 's') : n + ' of ' + total;
        },
        setAuditQueue() {
            this.status = 'pending_audit';
            this.syncUrl();
        },
        clearFilters() {
            this.search = '';
            this.status = '';
            this.source = '';
            this.syncUrl();
        },
        syncUrl() {
            const u = new URL(window.location.href);
            if (this.search) u.searchParams.set('search', this.search);
            else u.searchParams.delete('search');
            if (this.status) u.searchParams.set('status', this.status);
            else u.searchParams.delete('status');
            if (this.source) u.searchParams.set('source', this.source);
            else u.searchParams.delete('source');
            u.searchParams.delete('view');
            u.searchParams.delete('audit_queue');
            window.history.replaceState({}, '', u.pathname + u.search);
        },
    };
}
</script>
@endpush
