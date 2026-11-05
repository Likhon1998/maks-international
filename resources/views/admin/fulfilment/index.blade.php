@extends('layouts.app')
@section('title', 'Warehouse desk')
@section('content')
    <div
        class="fw"
        x-data="fulfilmentWorkspace({
            rows: @js($rows),
            details: @js($details),
            counts: @js($counts),
            drivers: @js($drivers),
            permissions: @js($permissions),
            stages: @js($stages),
            csrf: @js($csrf),
            initialStatus: @js($initialStatus),
            initialId: {{ (int) $initialId }},
            workspaceBase: @js(url('/admin/fulfilment')),
        })"
        @keydown.escape.window="closeDetail()"
    >
        <div class="fw-top">
            <div class="page-kicker"><strong>Warehouse desk</strong> / Approve, then deliver</div>
            <div class="fw-top-actions">
                <button type="button" class="btn btn-ghost btn-sm" @click="listCollapsed = !listCollapsed; $nextTick(() => { if (window.lucide) lucide.createIcons(); })" :disabled="busy">
                    <span x-text="listCollapsed ? 'Show list' : 'Hide list'"></span>
                </button>
                <a class="btn btn-ghost btn-sm" href="{{ route('deliveries.index') }}">Deliveries</a>
                <button type="button" class="btn btn-ghost btn-sm" @click="refreshSelected()" :disabled="!selectedId || busy || loadingDetail">
                    <span class="fw-btn-inner">
                        <span class="fw-spinner sm" x-show="loadingDetail"></span>
                        <span x-text="loadingDetail ? '…' : 'Refresh'"></span>
                    </span>
                </button>
            </div>
        </div>

        <div class="fw-toast" x-show="toast" x-cloak x-text="toast" :class="toastTone"></div>

        <div class="fw-stages" :class="{ 'is-locked': busy }">
            <button type="button" class="fw-stage" :class="{ active: status === 'work' }" @click="setStatus('work')" :disabled="busy">
                <span class="fw-stage-count" x-text="workCount()"></span>
                <span class="fw-stage-label">To deliver</span>
            </button>
            <button type="button" class="fw-stage" :class="{ active: status === 'delivered' }" @click="setStatus('delivered')" :disabled="busy">
                <span class="fw-stage-count" x-text="counts.delivered || 0"></span>
                <span class="fw-stage-label">Delivered</span>
            </button>
        </div>

        <div class="fw-layout" :class="{ 'list-collapsed': listCollapsed }">
            <section class="fw-list card" :class="{ 'is-locked': busy, collapsed: listCollapsed }" x-show="!listCollapsed" x-cloak>
                <div class="fw-list-head">
                    <div class="fw-list-head-row">
                        <input class="input input-sm" type="search" x-model="search" placeholder="Search…" autocomplete="off" :disabled="busy">
                        <button type="button" class="icon-btn fw-list-toggle" @click="listCollapsed = true" title="Hide list" :disabled="busy">
                            <i data-lucide="panel-left-close"></i>
                        </button>
                    </div>
                </div>
                <div class="fw-list-body">
                    <template x-for="row in filteredRows()" :key="row.id">
                        <button
                            type="button"
                            class="fw-row"
                            :class="{ active: selectedId === row.id }"
                            @click="open(row.id)"
                            :disabled="busy"
                        >
                            <div class="fw-row-main">
                                <div class="fw-row-title" x-text="row.order_number"></div>
                                <div class="fw-row-sub" x-text="row.shop"></div>
                            </div>
                            <span class="badge" :class="badgeClass(row.status)" x-text="shortStatus(row.status)"></span>
                        </button>
                    </template>
                    <div class="fw-empty" x-show="filteredRows().length === 0">No orders</div>
                </div>
            </section>

            <button
                type="button"
                class="fw-list-rail card"
                x-show="listCollapsed"
                x-cloak
                @click="listCollapsed = false; $nextTick(() => { if (window.lucide) lucide.createIcons(); })"
                title="Show order list"
            >
                <i data-lucide="panel-left-open"></i>
                <span x-text="filteredRows().length"></span>
            </button>

            <section class="fw-panel card">
                <template x-if="!detail && !loadingDetail">
                    <div class="fw-panel-empty muted">Select an order</div>
                </template>

                <template x-if="loadingDetail && !detail">
                    <div class="fw-panel-empty muted">
                        <span class="fw-spinner"></span>
                    </div>
                </template>

                <template x-if="detail">
                    <div class="fw-panel-inner">
                        <div class="fw-panel-head">
                            <div>
                                <div class="fw-title-row">
                                    <span class="fw-title" x-text="detail.order_number"></span>
                                    <span class="badge" :class="badgeClass(detail.status)" x-text="detail.status_label"></span>
                                </div>
                                <div class="fw-sub">
                                    <span x-text="detail.shop"></span>
                                    <span x-show="detail.warehouse_code"> · <span x-text="detail.warehouse_code"></span></span>
                                    <span> · </span>
                                    <strong x-text="detail.total"></strong>
                                </div>
                            </div>
                        </div>

                        <div class="fw-pipeline">
                            <template x-for="(step, idx) in detail.track" :key="step.key">
                                <div class="fw-pipe-step" :class="{ done: step.done, current: step.current }" :title="step.at || step.label">
                                    <div class="fw-pipe-dot"></div>
                                    <div class="fw-pipe-label" x-text="shortStage(step.key)"></div>
                                </div>
                            </template>
                        </div>

                        <div class="fw-split">
                            <div class="fw-lines">
                                <table class="data fw-table">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th>Qty</th>
                                            <th>Rsv</th>
                                            <th>Amt</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="item in detail.items" :key="item.sku + item.name">
                                            <tr>
                                                <td>
                                                    <div class="fw-item-name" x-text="item.name"></div>
                                                    <div class="muted fw-item-sku" x-text="item.sku"></div>
                                                </td>
                                                <td style="font-weight:700" x-text="item.qty"></td>
                                                <td x-text="item.reserved"></td>
                                                <td x-text="item.line"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                                <div class="fw-meta-line muted" x-show="detail.picker || detail.packer || detail.dispatcher || detail.notes">
                                    <span x-show="detail.picker">Pick: <span x-text="detail.picker"></span></span>
                                    <span x-show="detail.packer"> · Pack: <span x-text="detail.packer"></span></span>
                                    <span x-show="detail.dispatcher"> · Out: <span x-text="detail.dispatcher"></span></span>
                                    <span x-show="detail.notes"> · <span x-text="detail.notes"></span></span>
                                </div>
                            </div>

                            <div class="fw-actions" :class="{ 'is-busy': busy }">
                                <div class="fw-busy-veil" x-show="busy" x-cloak>
                                    <div class="fw-busy-box">
                                        <span class="fw-spinner lg"></span>
                                        <div class="fw-busy-title" x-text="busyLabel"></div>
                                    </div>
                                </div>

                                <div class="fw-action-card" x-show="detail.can_deliver_now">
                                    <div class="fw-action-title">Assign & deliver</div>
                                    <p class="muted" style="margin:0 0 8px;font-size:12px">Choose the salesman who will take and deliver this order.</p>
                                    <label class="label" style="margin-bottom:4px">Salesman</label>
                                    <select class="select select-sm" x-model="assignedTo" :disabled="busy" style="margin-bottom:8px;width:100%">
                                        <option value="">Select salesman…</option>
                                        <template x-for="driver in drivers" :key="driver.id">
                                            <option :value="String(driver.id)" x-text="driver.name"></option>
                                        </template>
                                    </select>
                                    <input class="input input-sm" x-model="notes" placeholder="Notes (optional)" :disabled="busy" style="margin-bottom:8px">
                                    <button type="button" class="btn btn-primary btn-sm" @click="run('deliver')" :disabled="busy || !assignedTo">
                                        <span class="fw-btn-inner">
                                            <span class="fw-spinner sm light" x-show="busyAction === 'deliver'"></span>
                                            <span x-text="busyAction === 'deliver' ? 'Saving…' : 'Assign & mark delivered'"></span>
                                        </span>
                                    </button>
                                </div>

                                <div class="fw-action-card" x-show="detail.delivery_url">
                                    <div class="fw-deliver-ref muted">
                                        Sent as <span x-text="detail.delivery_number"></span>
                                        <span x-show="detail.assignee"> · <span x-text="detail.assignee"></span></span>
                                    </div>
                                    <a class="btn btn-ghost btn-sm" :href="detail.delivery_url">Open delivery</a>
                                </div>

                                <div class="fw-action-card is-done" x-show="detail.status === 'delivered'">
                                    <div class="fw-action-title">Delivered</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </section>
        </div>
    </div>
@endsection

@push('scripts')
<script>
function fulfilmentWorkspace(cfg) {
    return {
        rows: cfg.rows || [],
        details: cfg.details || {},
        counts: cfg.counts || {},
        drivers: cfg.drivers || [],
        permissions: cfg.permissions || {},
        stages: cfg.stages || {},
        shortStages: {
            ready: 'Approved',
            delivered: 'Delivered',
        },
        csrf: cfg.csrf,
        workspaceBase: cfg.workspaceBase,
        status: cfg.initialStatus || 'work',
        search: '',
        listCollapsed: localStorage.getItem('bt_fw_list_collapsed') === '1',
        selectedId: null,
        detail: null,
        loadingDetail: false,
        busy: false,
        busyAction: '',
        busyLabel: 'Working…',
        toast: '',
        toastTone: 'ok',
        notes: '',
        assignedTo: '',
        trackingRef: '',
        init() {
            this.$watch('listCollapsed', (v) => localStorage.setItem('bt_fw_list_collapsed', v ? '1' : '0'));
            if (cfg.initialId) this.open(cfg.initialId);
            else {
                const first = this.filteredRows()[0];
                if (first) this.open(first.id);
            }
            this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
        },
        shortStage(key) {
            return this.shortStages[key] || key;
        },
        shortStatus(status) {
            return status === 'delivered' ? 'Delivered' : 'Approved';
        },
        actionLabel(action) {
            return ({
                start_pick: 'Starting…',
                complete_pick: 'Picking…',
                pack: 'Packing…',
                dispatch: 'Dispatching…',
                deliver: 'Saving…',
            })[action] || 'Working…';
        },
        workCount() {
            return this.rows.filter((r) => r.status !== 'delivered').length;
        },
        setStatus(status) {
            if (this.busy) return;
            this.status = status;
            const u = new URL(window.location.href);
            if (status && status !== 'work') u.searchParams.set('status', status);
            else u.searchParams.delete('status');
            if (this.selectedId) u.searchParams.set('open', String(this.selectedId));
            history.replaceState({}, '', u.pathname + u.search);
            const list = this.filteredRows();
            if (!list.find((r) => r.id === this.selectedId)) {
                if (list[0]) this.open(list[0].id);
                else this.closeDetail();
            }
        },
        filteredRows() {
            const q = (this.search || '').trim().toLowerCase();
            return this.rows.filter((row) => {
                if (this.status === 'work') {
                    if (row.status === 'delivered') return false;
                } else if (this.status && row.status !== this.status) {
                    return false;
                }
                if (!q) return true;
                return String(row.order_number || '').toLowerCase().includes(q)
                    || String(row.shop || '').toLowerCase().includes(q);
            });
        },
        cachedDetail(id) {
            return this.details[id] || this.details[String(id)] || null;
        },
        open(id) {
            if (this.busy) return;
            this.selectedId = id;
            this.notes = '';
            this.trackingRef = '';
            this.assignedTo = '';
            const u = new URL(window.location.href);
            u.searchParams.set('open', String(id));
            if (this.status && this.status !== 'work') u.searchParams.set('status', this.status);
            else u.searchParams.delete('status');
            history.replaceState({}, '', u.pathname + u.search);

            const cached = this.cachedDetail(id);
            if (cached) {
                this.detail = cached;
                this.loadingDetail = false;
                return;
            }
            this.fetchDetail(id);
        },
        async fetchDetail(id) {
            this.loadingDetail = true;
            try {
                const res = await fetch(`${this.workspaceBase}/${id}/workspace`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                if (!res.ok) throw new Error('Could not load order.');
                const data = await res.json();
                this.upsertRow(data.row);
                this.details[id] = data.detail;
                if (this.selectedId === id) this.detail = data.detail;
                if (data.counts) this.counts = data.counts;
            } catch (e) {
                this.showToast(e.message || 'Failed to load.', 'err');
            } finally {
                this.loadingDetail = false;
            }
        },
        closeDetail() {
            if (this.busy) return;
            this.selectedId = null;
            this.detail = null;
        },
        refreshSelected() {
            if (this.selectedId) this.fetchDetail(this.selectedId);
        },
        upsertRow(row) {
            if (!row) return;
            const i = this.rows.findIndex((r) => r.id === row.id);
            if (i >= 0) this.rows[i] = row;
            else this.rows.unshift(row);
        },
        async run(action) {
            if (!this.detail || this.busy) return;
            const url = this.detail.urls?.[action];
            if (!url) return;

            const body = new FormData();
            body.append('_token', this.csrf);
            if (this.notes) {
                if (action === 'deliver' || action === 'dispatch') body.append('notes', this.notes);
                else body.append('warehouse_notes', this.notes);
            }
            if (action === 'dispatch' || action === 'deliver') {
                if (this.assignedTo) body.append('assigned_to', this.assignedTo);
                if (action === 'dispatch' && this.trackingRef) body.append('tracking_ref', this.trackingRef);
            }

            this.busy = true;
            this.busyAction = action;
            this.busyLabel = this.actionLabel(action);
            try {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    body,
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) {
                    const msg = data.message || data.errors && Object.values(data.errors).flat()[0] || 'Action failed.';
                    throw new Error(typeof msg === 'string' ? msg : 'Action failed.');
                }
                this.upsertRow(data.row);
                this.detail = data.detail;
                if (data.detail?.id) this.details[data.detail.id] = data.detail;
                if (data.counts) this.counts = data.counts;
                this.notes = '';
                this.trackingRef = '';
                this.showToast(data.message || 'Updated.', 'ok');
                if (this.status !== 'work' && data.row?.status) this.status = data.row.status;
            } catch (e) {
                this.showToast(e.message || 'Action failed.', 'err');
            } finally {
                this.busy = false;
                this.busyAction = '';
                this.busyLabel = 'Working…';
            }
        },
        showToast(msg, tone) {
            this.toast = msg;
            this.toastTone = tone === 'err' ? 'err' : 'ok';
            clearTimeout(this._toastTimer);
            this._toastTimer = setTimeout(() => { this.toast = ''; }, 2800);
        },
        badgeClass(status) {
            return ({
                awaiting_pick: 'badge-pending',
                picking: 'badge-processing',
                picked: 'badge-processing',
                packed: 'badge-shipped',
                dispatched: 'badge-transit',
                delivered: 'badge-delivered',
            })[status] || 'badge-hold';
        },
    };
}
</script>
@endpush
