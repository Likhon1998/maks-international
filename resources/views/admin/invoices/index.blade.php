@extends('layouts.app')
@section('title', 'Invoices')
@section('content')
    <div x-data="invoiceBoard(@js($rows), @js($initialFilters))">
        <x-page-header title="Invoices" subtitle="Issued from delivered orders · credit outstanding">
            <x-slot:description>Invoices raise shop AR. Open balances feed credit hold when over limit. Search updates live.</x-slot:description>
        </x-page-header>

        @if (session('success'))
            <div class="card" style="padding:12px 16px;margin-bottom:14px;background:#e8f8ee;color:#15803d;display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between">
                <span>{{ session('success') }}</span>
                @if (session('invoice_download'))
                    <a class="btn btn-primary btn-sm" href="{{ session('invoice_download') }}">Download invoice PDF</a>
                @endif
            </div>
        @endif

        <div class="card" style="padding:14px;margin-bottom:14px">
            <div class="filters">
                <input
                    class="input"
                    type="search"
                    x-model="search"
                    @input="syncUrl()"
                    placeholder="Invoice #, shop, order"
                    autocomplete="off"
                    style="min-width:200px;flex:1"
                >
                <select class="select" x-model="status" @change="syncUrl()">
                    <option value="">All statuses</option>
                    <option value="issued">Issued</option>
                    <option value="partial">Partially paid</option>
                    <option value="paid">Paid</option>
                    <option value="void">Void</option>
                </select>
                <button type="button" class="btn btn-ghost" x-show="search || status" x-cloak @click="clearFilters()">Clear</button>
                <span class="muted" style="font-size:12px;margin-left:auto" x-text="filteredCountLabel()"></span>
            </div>
        </div>

        <div class="card">
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Shop</th>
                            <th class="col-hide-md">Order</th>
                            <th class="col-hide-lg">Issued</th>
                            <th class="col-hide-lg">Due</th>
                            <th>Total</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th class="actions"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="row in filteredRows()" :key="row.id">
                            <tr>
                                <td>
                                    <a class="link" :href="row.show_url" x-text="row.number"></a>
                                    <div class="muted" style="font-size:11px" x-text="row.kind"></div>
                                </td>
                                <td>
                                    <span x-text="row.shop"></span>
                                    <div class="muted" style="font-size:11px" x-show="row.shop_code" x-text="row.shop_code"></div>
                                </td>
                                <td class="muted col-hide-md" x-text="row.order || '—'"></td>
                                <td class="muted col-hide-lg" x-text="row.issued_at"></td>
                                <td class="muted col-hide-lg" x-text="row.due_at"></td>
                                <td style="font-weight:700" x-text="row.total"></td>
                                <td style="font-weight:600" :style="'color:' + (row.balance_raw > 0 ? '#b91c1c' : '#15803d')" x-text="row.balance"></td>
                                <td>
                                    <span
                                        class="badge"
                                        :class="{
                                            'badge-paid': row.status === 'paid',
                                            'badge-partial': row.status === 'partial',
                                            'badge-pending': row.status === 'issued',
                                            'badge-hold': row.status === 'void'
                                        }"
                                        x-text="row.status_label"
                                    ></span>
                                </td>
                                <td class="actions">
                                    <div class="row-menu" x-data="{ open: false }" @keydown.escape.window="open = false">
                                        <button type="button" class="row-menu-btn" @click="open = !open" :aria-expanded="open.toString()" aria-haspopup="menu" title="Actions">
                                            <span aria-hidden="true">⋯</span>
                                        </button>
                                        <div class="row-menu-panel" role="menu" x-cloak x-show="open" @click.outside="open = false" @click="open = false">
                                            <a class="row-menu-item" :href="row.show_url">Open</a>
                                            <a class="row-menu-item" :href="row.download_url">Download PDF</a>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="filteredRows().length === 0">
                            <td colspan="9" class="muted" style="padding:24px;text-align:center">
                                No invoices match your filters.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
function invoiceBoard(rows, initialFilters) {
    return {
        rows: rows || [],
        search: initialFilters?.search || '',
        status: initialFilters?.status || '',
        filteredRows() {
            const q = (this.search || '').trim().toLowerCase();
            return this.rows.filter((row) => {
                if (this.status && row.status !== this.status) return false;
                if (!q) return true;
                return (
                    String(row.number || '').toLowerCase().includes(q) ||
                    String(row.shop || '').toLowerCase().includes(q) ||
                    String(row.shop_code || '').toLowerCase().includes(q) ||
                    String(row.order || '').toLowerCase().includes(q) ||
                    String(row.kind || '').toLowerCase().includes(q)
                );
            });
        },
        filteredCountLabel() {
            const n = this.filteredRows().length;
            const total = this.rows.length;
            return n === total ? n + ' invoice' + (n === 1 ? '' : 's') : n + ' of ' + total;
        },
        clearFilters() {
            this.search = '';
            this.status = '';
            this.syncUrl();
        },
        syncUrl() {
            const u = new URL(window.location.href);
            if (this.search) u.searchParams.set('search', this.search);
            else u.searchParams.delete('search');
            if (this.status) u.searchParams.set('status', this.status);
            else u.searchParams.delete('status');
            window.history.replaceState({}, '', u.pathname + u.search);
        },
    };
}
</script>
@endpush
