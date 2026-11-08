@extends('layouts.app')
@section('title', 'Record payment')
@section('content')
@php
    $shopOptions = $shops->map(fn ($s) => [
        'id' => $s->id,
        'name' => $s->name,
        'code' => $s->code,
        'outstanding' => (float) $s->outstanding_balance,
        'outstanding_label' => \App\Support\DemoData::taka($s->outstanding_balance),
    ])->values();

    $invoiceOptions = $openInvoices->map(function ($inv) {
        $isAdvance = $inv->order && (int) $inv->order->advance_invoice_id === (int) $inv->id;
        $kind = $isAdvance || str_contains(strtolower((string) $inv->notes), 'advance')
            ? 'Advance'
            : 'Sales';

        return [
            'id' => $inv->id,
            'shop_id' => $inv->shop_id,
            'number' => $inv->number,
            'shop' => $inv->shop?->name,
            'kind' => $kind,
            'balance' => (float) $inv->balance,
            'balance_label' => \App\Support\DemoData::taka($inv->balance),
            'total_label' => \App\Support\DemoData::taka($inv->total),
            'paid_label' => \App\Support\DemoData::taka($inv->paid_amount),
            'status' => $inv->statusLabel(),
            'order' => $inv->order?->number,
        ];
    })->values();

    $initialShop = (int) old('shop_id', $prefillShopId ?? 0);
    $initialInvoice = (int) old('invoice_id', $prefillInvoiceId ?? 0);
    if ($initialInvoice && ! $initialShop) {
        $match = $invoiceOptions->firstWhere('id', $initialInvoice);
        $initialShop = (int) ($match['shop_id'] ?? 0);
    }
@endphp

<div class="pay-create-page">
    <div class="pay-create-head">
        <div>
            <div class="page-kicker"><strong>Record payment</strong></div>
            <p class="muted" style="margin:2px 0 0;font-size:12px">Select shop → see due → pay any amount</p>
        </div>
        <a class="btn btn-ghost btn-sm" href="{{ route('payments.index') }}">Back</a>
    </div>

    @if ($errors->any())
        <div class="card" style="padding:10px 12px;margin-bottom:10px;background:#fef2f2;color:#b91c1c">{{ $errors->first() }}</div>
    @endif

    <div
        class="card pay-create-card"
        x-data="paymentForm(@js($shopOptions), @js($invoiceOptions), {{ $initialShop }}, {{ $initialInvoice }}, @js(old('amount')))"
    >
        <form method="post" action="{{ route('payments.store') }}" class="pay-create-form">
            @csrf
            <div class="pay-create-row">
                <div class="field">
                    <label class="label">Shop</label>
                    <select class="select" name="shop_id" x-model="shopId" @change="onShopChange()" required>
                        <option value="">Select shop</option>
                        <template x-for="shop in shops" :key="shop.id">
                            <option :value="shop.id" x-text="shop.name + ' · ' + shop.code"></option>
                        </template>
                    </select>
                </div>
                <div class="field">
                    <label class="label">Invoice</label>
                    <select class="select" name="invoice_id" x-model="invoiceId" @change="onInvoiceChange()">
                        <option value="">Unallocated / shop-level</option>
                        <template x-for="inv in filteredInvoices()" :key="inv.id">
                            <option :value="inv.id" x-text="inv.number + ' · ' + inv.kind + ' · ' + inv.balance_label"></option>
                        </template>
                    </select>
                </div>
            </div>

            <div class="pay-due-panel" x-show="shopId" x-cloak>
                <div class="pay-due-grid">
                    <div>
                        <span>Shop outstanding</span>
                        <strong x-text="selectedShop()?.outstanding_label || '৳ 0'"></strong>
                    </div>
                    <div x-show="selectedInvoice()">
                        <span>Invoice due</span>
                        <strong x-text="selectedInvoice()?.balance_label || '—'"></strong>
                    </div>
                    <div class="pay-due-rest">
                        <span>Collect now</span>
                        <strong x-text="dueLabel()"></strong>
                        <em x-text="dueHint()"></em>
                    </div>
                </div>
                <div class="pay-due-actions" x-show="dueAmount() > 0">
                    <button type="button" class="btn btn-primary btn-sm" @click="fillDue()">Pay full due</button>
                    <button type="button" class="btn btn-ghost btn-sm" @click="fillHalf()" x-show="dueAmount() >= 2">Pay half</button>
                </div>
            </div>

            <div class="pay-create-row">
                <div class="field">
                    <label class="label">Amount (BDT)</label>
                    <input class="input" type="number" step="0.01" min="0.01" name="amount" x-model="amount" required>
                </div>
                <div class="field">
                    <label class="label">Method</label>
                    <select class="select" name="method" required>
                        @foreach (['cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'mobile_banking' => 'Mobile banking', 'cheque' => 'Cheque'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('method', 'cash') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="pay-create-row">
                <div class="field">
                    <label class="label">Reference</label>
                    <input class="input" name="reference" value="{{ old('reference') }}" placeholder="Txn / cheque #">
                </div>
                <div class="field">
                    <label class="label">Paid at</label>
                    <input class="input" type="datetime-local" name="paid_at" value="{{ old('paid_at', now()->format('Y-m-d\TH:i')) }}">
                </div>
            </div>

            <div class="field">
                <label class="label">Notes <span class="muted" style="font-weight:500">(optional)</span></label>
                <input class="input" name="notes" value="{{ old('notes') }}" placeholder="Short note">
            </div>

            @can('payments.verify')
                <label class="pay-verify-row">
                    <input type="checkbox" name="verify_now" value="1" @checked(old('verify_now', true))>
                    <span>Verify immediately</span>
                </label>
            @endcan

            <div class="pay-create-actions">
                <button class="btn btn-primary" type="submit">Save payment</button>
                <a class="btn btn-ghost" href="{{ route('payments.index') }}">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
function paymentForm(shops, invoices, initialShop, initialInvoice, initialAmount) {
    return {
        shops: shops || [],
        invoices: invoices || [],
        shopId: initialShop ? String(initialShop) : '',
        invoiceId: initialInvoice ? String(initialInvoice) : '',
        amount: initialAmount != null && initialAmount !== '' ? String(initialAmount) : '',
        init() {
            if (this.invoiceId && !this.amount) this.fillDue();
            else if (this.shopId && !this.amount) this.fillDue();
        },
        selectedShop() {
            const id = parseInt(this.shopId || 0, 10);
            return this.shops.find((s) => s.id === id) || null;
        },
        selectedInvoice() {
            const id = parseInt(this.invoiceId || 0, 10);
            return this.invoices.find((i) => i.id === id) || null;
        },
        filteredInvoices() {
            const id = parseInt(this.shopId || 0, 10);
            if (!id) return this.invoices;
            return this.invoices.filter((i) => i.shop_id === id);
        },
        dueAmount() {
            const inv = this.selectedInvoice();
            if (inv) return Math.max(0, Number(inv.balance) || 0);
            const shop = this.selectedShop();
            return shop ? Math.max(0, Number(shop.outstanding) || 0) : 0;
        },
        dueLabel() {
            const n = this.dueAmount();
            return '৳ ' + Math.round(n).toLocaleString('en-IN');
        },
        dueHint() {
            if (this.selectedInvoice()) {
                const inv = this.selectedInvoice();
                return (inv.kind || 'Invoice') + ' ' + inv.number + (inv.order ? ' · ' + inv.order : '');
            }
            return 'Shop-level outstanding';
        },
        onShopChange() {
            const list = this.filteredInvoices();
            if (this.invoiceId && !list.some((i) => String(i.id) === String(this.invoiceId))) {
                this.invoiceId = '';
            }
            // Prefer an open invoice so collecting due reduces invoice balances, not only shop credit.
            if (!this.invoiceId && list.length > 0) {
                this.invoiceId = String(list[0].id);
            }
            this.fillDue();
        },
        onInvoiceChange() {
            const inv = this.selectedInvoice();
            if (inv) this.shopId = String(inv.shop_id);
            this.fillDue();
        },
        fillDue() {
            const due = this.dueAmount();
            this.amount = due > 0 ? String(Math.round(due * 100) / 100) : '';
        },
        fillHalf() {
            const due = this.dueAmount();
            this.amount = due > 0 ? String(Math.round((due / 2) * 100) / 100) : '';
        },
    };
}
</script>
@endpush
