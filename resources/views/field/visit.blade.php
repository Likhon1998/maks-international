@extends('field.layouts.app')
@php
    $shop = $visit->shop;
    $phone = preg_replace('/[^0-9+]/', '', (string) $shop?->phone);
    $outstanding = (float) ($shop?->outstanding_balance ?? 0);
    $taking = $visit->isOpen() && ! $visit->order && $canOrder;
@endphp
@section('title', 'Visit · '.$shop?->name)
@section('heading', $shop?->name)
@section('subheading', ($visit->isOpen() ? 'Checked in '.$visit->checked_in_at?->format('g:i A') : 'Visit closed').' · '.$visit->outcomeLabel())
@section('back')
    {{ $visit->isOpen() ? '' : route('field.shops') }}
@endsection
@section('back_post')
    {{ $visit->isOpen() ? route('field.visit.leave', $visit) : '' }}
@endsection
@if ($taking)
    @section('has-bar', '1')
@endif
@section('actions')
    @if ($visit->isOpen())
        <button type="button" class="f-btn f-btn-ghost f-btn-sm f-desktop-only" onclick="window.dispatchEvent(new CustomEvent('open-leave', { detail: 'shops' }))">
            <i data-lucide="log-out"></i> {{ __('field.im_leaving_btn') }}
        </button>
    @endif
@endsection
@section('content')
    <div class="f-card f-visit-shop">
        <div class="f-visit-shop-top">
            <div class="f-shop-ico"><i data-lucide="store"></i></div>
            <div class="f-visit-shop-meta">
                <div class="f-shop-name">{{ $shop?->name }}</div>
                <div class="f-shop-meta">{{ collect([$shop?->owner_name, $shop?->address ?: $shop?->city])->filter()->implode(' · ') }}</div>
            </div>
            @if ($phone)
                <a class="f-btn f-btn-soft f-btn-icon" href="tel:{{ $phone }}" aria-label="Call shop"><i data-lucide="phone"></i></a>
            @endif
        </div>
        <div class="f-chips">
            @if ($canOrder)
                <span class="f-chip {{ $outstanding > 0 ? 'red' : 'green' }}">Baki {{ \App\Support\DemoData::taka($outstanding) }}</span>
            @else
                <span class="f-chip amber">Awaiting approval</span>
            @endif
            @if ($shop?->priceGroup)<span class="f-chip">{{ $shop->priceGroup->name }}</span>@endif
        </div>
        @if ($visit->isOpen())
            <div class="f-here">
                <span><b>{{ __('field.you_are_at', ['shop' => $shop?->name]) }}</b>{{ __('field.checked_in_ago', ['when' => $visit->checked_in_at?->diffForHumans()]) }}</span>
                <button type="button" class="f-btn f-btn-ghost f-btn-sm" onclick="window.dispatchEvent(new CustomEvent('open-leave', { detail: 'shops' }))">
                    <i data-lucide="log-out"></i> {{ __('field.im_leaving_btn') }}
                </button>
            </div>
        @endif
    </div>

    @if ($visit->order)
        @php $s = $visit->order->simpleStatus(); @endphp
        <div class="f-card">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px">
                <div>
                    <div class="f-muted f-small">Order taken on this visit</div>
                    <div style="font-weight:800;font-size:18px">{{ \App\Support\DemoData::taka($visit->order->total) }}</div>
                </div>
                <span class="f-status {{ $s['tone'] }}">{{ $s['label'] }}</span>
            </div>
            <a class="f-btn f-btn-ghost f-btn-block" style="margin-top:12px" href="{{ route('field.orders.show', $visit->order) }}">View order {{ $visit->order->number }}</a>
        </div>
        @if ($visit->isOpen())
            <button class="f-btn f-btn-primary f-btn-lg f-btn-block" type="button"
                onclick="window.dispatchEvent(new CustomEvent('open-leave', { detail: 'shops' }))">
                <i data-lucide="log-out"></i> Finish visit
            </button>
        @endif
    @elseif (! $visit->isOpen())
        <div class="f-empty">
            <i data-lucide="calendar-check"></i>
            <strong>Visit closed · {{ $visit->outcomeLabel() }}</strong>
            {{ $visit->checked_out_at?->format('d M, g:i A') }}
        </div>
        @if ($visit->notes)
            <div class="f-card" style="margin-top:10px">
                <div class="f-muted f-small">Talked with the owner</div>
                <div style="margin-top:4px">{{ $visit->notes }}</div>
            </div>
        @endif
        @if ($visit->photoUrl())
            <div class="f-card" style="margin-top:10px;padding:8px">
                <img src="{{ $visit->photoUrl() }}" alt="Visit evidence" style="width:100%;border-radius:10px;display:block">
            </div>
        @endif
        <a class="f-btn f-btn-primary f-btn-block f-btn-lg" style="margin-top:12px" href="{{ route('field.shops') }}">Back to my shops</a>
    @elseif (! $canOrder)
        <div class="f-warn" style="margin:0 0 12px">
            @if ($shop?->status === \App\Models\Shop::STATUS_PENDING)
                <b>{{ $shop->name }} is not approved yet.</b> Approve it to take orders on this visit.
                <form method="post" action="{{ route('field.shops.approve', $shop) }}" style="margin-top:10px">
                    @csrf
                    <button class="f-btn f-btn-primary f-btn-lg f-btn-block" type="submit"><i data-lucide="check"></i> Approve shop &amp; take orders</button>
                </form>
            @else
                <b>{{ $shop?->name }} cannot take orders</b> ({{ strtolower($shop?->statusLabel() ?? 'unavailable') }}). Ask the office if it was deactivated.
            @endif
        </div>
        <button class="f-btn f-btn-primary f-btn-lg f-btn-block" type="button"
            onclick="window.dispatchEvent(new CustomEvent('open-leave', { detail: 'shops' }))">
            <i data-lucide="log-out"></i> End visit
        </button>
    @else
        <div x-data="fieldOrder(@js($catalog))" x-cloak>
          <div class="f-visit">
          <div class="f-visit-main">
            <div class="f-toolbar">
            <div class="f-search" style="margin-top:4px">
                <i data-lucide="search"></i>
                <input type="search" x-model="q" @input="onSearch()" placeholder="Search product or SKU" autocomplete="off" aria-label="Search products">
            </div>
            <div class="f-filters" x-show="view === 'products'">
                <button type="button" class="f-filter" @click="backToCategories()"><i data-lucide="layout-grid" style="width:14px;height:14px"></i> Categories</button>
                <button type="button" class="f-filter" :class="cat === '' && !q && 'active'" @click="showAllProducts()">All</button>
                <template x-for="c in categoryCards()" :key="c.name">
                    <button type="button" class="f-filter" :class="cat === c.name && 'active'" @click="openCategory(c.name)" x-text="c.name"></button>
                </template>
                <button type="button" class="f-filter" :class="cat === '__picked' && 'active'" @click="showPicked()" x-show="count() > 0" x-text="'In order (' + lines().length + ')'"></button>
            </div>
            </div>

            {{-- Category picker (default) --}}
            <div class="f-cat-grid" x-show="view === 'categories' && !q" x-cloak>
                <button type="button" class="f-cat-card tone-violet" @click="showAllProducts()">
                    <span class="f-cat-ico"><i data-lucide="package"></i></span>
                    <span class="f-cat-name">All products</span>
                    <span class="f-cat-count" x-text="catalog.length + ' items'"></span>
                </button>
                <template x-for="(c, i) in categoryCards()" :key="c.name">
                    <button type="button" class="f-cat-card" :class="'tone-' + ['blue','green','amber','rose','sky','teal','violet'][i % 7]" @click="openCategory(c.name)">
                        <span class="f-cat-ico"><i data-lucide="tag"></i></span>
                        <span class="f-cat-name" x-text="c.name"></span>
                        <span class="f-cat-count" x-text="c.count + (c.count === 1 ? ' product' : ' products')"></span>
                    </button>
                </template>
                <button type="button" class="f-cat-card tone-amber" @click="showPicked()" x-show="count() > 0">
                    <span class="f-cat-ico"><i data-lucide="shopping-cart"></i></span>
                    <span class="f-cat-name">In this order</span>
                    <span class="f-cat-count" x-text="lines().length + ' · ' + taka(total())"></span>
                </button>
            </div>

            <div class="f-card f-products" style="padding:4px 14px" x-show="view === 'products' || q" x-cloak>
                <div class="f-muted f-small" style="padding:8px 0 4px" x-show="!q && cat && cat !== '__picked'" x-text="'Showing ' + cat"></div>
                <div class="f-muted f-small" style="padding:8px 0 4px" x-show="cat === '__picked'">Products in this order — tap price to change</div>
                <template x-for="p in filtered()" :key="p.id">
                    <div class="f-product" :class="{ picked: qty[p.id] > 0, oos: p.stock <= 0 }">
                        <template x-if="p.image"><img class="f-thumb" :src="p.image" alt="" loading="lazy"></template>
                        <template x-if="!p.image"><div class="f-thumb"><i data-lucide="package"></i></div></template>
                        <div class="f-product-info">
                            <div class="f-product-name" x-text="p.name"></div>
                            <div class="f-muted f-small" x-show="!cat || cat === ''" x-text="p.category"></div>
                            <div class="f-product-price" x-show="!qty[p.id]" x-text="taka(p.price)"></div>
                            <label class="f-price-edit" x-show="qty[p.id] > 0" x-cloak>
                                <span>৳</span>
                                <input type="number" inputmode="decimal" step="0.01" min="0" :value="sellPrice(p)" @change="setPrice(p.id, $event.target.value)" :aria-label="'Price of ' + p.name">
                            </label>
                            <div class="f-muted f-small" x-show="qty[p.id] > 0 && changed(p) && !belowMin(p)" x-text="'List ' + taka(p.price)"></div>
                            <div class="f-stock low" x-show="qty[p.id] > 0 && belowMin(p)" x-text="'Cannot sell below ' + taka(p.min_price)"></div>
                            <div class="f-stock" :class="p.stock <= 0 ? 'out' : (p.stock <= 10 ? 'low' : 'ok')"
                                 x-text="p.stock <= 0 ? 'Out of stock' : (p.stock <= 10 ? 'Only ' + p.stock + ' left' : p.stock + ' in stock')"></div>
                        </div>
                        <template x-if="!qty[p.id]">
                            <button type="button" class="f-add" :disabled="p.stock <= 0" @click="inc(p.id)" :aria-label="p.stock <= 0 ? p.name + ' is out of stock' : 'Add ' + p.name" x-text="p.stock <= 0 ? 'Unavailable' : 'Add'"></button>
                        </template>
                        <template x-if="qty[p.id] > 0">
                            <div class="f-stepper">
                                <button type="button" @click="dec(p.id)" :aria-label="'Less ' + p.name"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M5 12h14"/></svg></button>
                                <input type="number" inputmode="numeric" min="0" :max="p.stock" :value="qty[p.id]" @change="set(p.id, $event.target.value)" @focus="$event.target.select()" :aria-label="'Quantity of ' + p.name">
                                <button type="button" @click="inc(p.id)" :disabled="!canAdd(p.id)" :aria-label="'More ' + p.name"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg></button>
                            </div>
                        </template>
                    </div>
                </template>
                <div x-show="filtered().length === 0" class="f-muted" style="padding:24px 0;text-align:center">No products match your search.</div>
            </div>

          </div>

          <aside class="f-visit-side">
            <div class="f-card f-summary">
                <div class="f-summary-head">
                    <h3>Order summary</h3>
                    <span class="f-chip" x-text="count() ? count() + ' pcs' : 'Empty'"></span>
                </div>
                <div class="f-summary-lines">
                    <template x-for="line in lines()" :key="line.id">
                        <div class="f-line-item">
                            <span><span x-text="line.name"></span> <span class="f-muted" x-text="'× ' + line.qty + ' · ' + taka(line.sell)"></span></span>
                            <span x-text="taka(line.qty * line.sell)"></span>
                        </div>
                    </template>
                    <div class="f-muted f-small" x-show="count() === 0" style="padding:18px 0;text-align:center">Click <b>Add</b> on a product to start the order.</div>
                </div>
                <div class="f-total-row"><span>Total</span><strong x-text="taka(total())"></strong></div>
                <div class="f-warn" x-show="hasBelowMin()">A selling price is below the product minimum. Raise it before sending.</div>
                <div class="f-warn" x-show="hasOverStock()">A product is over available stock. Reduce the quantity.</div>
                <button type="button" class="f-btn f-btn-primary f-btn-lg f-btn-block" style="margin-top:14px" :disabled="count() === 0 || hasBelowMin() || hasOverStock()" @click="reviewSheet = true">Review &amp; send order</button>
            </div>
          </aside>
          </div>

            {{-- Sticky running total --}}
            <div class="f-cartbar" role="region" aria-label="Order total">
                <div class="f-cartbar-text">
                    <small x-text="count() ? lines().length + ' products · ' + count() + ' pcs' : 'No products added yet'"></small>
                    <strong x-text="taka(total())"></strong>
                </div>
                <button type="button" class="f-btn f-btn-primary" :disabled="count() === 0 || hasOverStock()" @click="reviewSheet = true">Review order</button>
            </div>

            {{-- Review & submit --}}
            <div class="f-sheet-backdrop" x-show="reviewSheet" x-transition.opacity @click="reviewSheet = false"></div>
            <form class="f-sheet f-review" x-show="reviewSheet" x-transition method="post" action="{{ route('field.visit.order', $visit) }}" @submit="submitting = true" @keydown.escape.window="reviewSheet = false">
                @csrf
                <div class="f-sheet-grip"></div>
                <div class="f-review-head">
                    <div>
                        <h3>Review order</h3>
                        <div class="f-review-shop">{{ $shop?->name }}@if ($shop?->owner_name) · {{ $shop->owner_name }}@endif</div>
                    </div>
                    <button type="button" class="f-review-close" @click="reviewSheet = false" aria-label="Close">×</button>
                </div>

                <div class="f-review-lines">
                    <template x-for="(line, i) in lines()" :key="line.id">
                        <div class="f-review-line">
                            <div>
                                <strong x-text="line.name"></strong>
                                <span class="f-muted" x-text="line.qty + ' × ' + taka(line.sell)"></span>
                                <span class="f-muted" x-show="line.changed" x-text="' · list ' + taka(line.price)"></span>
                            </div>
                            <span class="f-review-amt" x-text="taka(line.qty * line.sell)"></span>
                            <input type="hidden" :name="'items[' + i + '][product_id]'" :value="line.id">
                            <input type="hidden" :name="'items[' + i + '][quantity]'" :value="line.qty">
                            <input type="hidden" :name="'items[' + i + '][unit_price]'" :value="line.sell">
                        </div>
                    </template>
                </div>
                <div class="f-warn" x-show="hasBelowMin()">Raise every selling price to at least the product minimum before sending.</div>

                <div class="f-review-box">
                    <div class="f-review-row">
                        <span>Subtotal</span>
                        <strong x-text="taka(total())"></strong>
                    </div>
                    <div class="f-review-disc">
                        <div class="f-review-row" style="padding-bottom:4px">
                            <span>Discount</span>
                            <strong x-show="discountAmount() > 0.009" x-text="'- ' + taka(discountAmount())"></strong>
                        </div>
                        <div class="f-disc-add" role="group" aria-label="Add discount">
                            <button type="button" @click="addDiscount(50)">+50</button>
                            <button type="button" @click="addDiscount(100)">+100</button>
                            <button type="button" @click="addDiscount(200)">+200</button>
                            <button type="button" @click="addPercent(10)">+10%</button>
                            <button type="button" class="is-clear" x-show="discountAmount() > 0.009" @click="clearDiscount()">Clear</button>
                        </div>
                        <div class="f-money f-money-sm">
                            <span>−৳</span>
                            <input id="order-discount" type="number" inputmode="decimal" min="0" step="1" name="discount_total" x-model.number="discount" :max="Math.round(total())" placeholder="Tap above or type more">
                        </div>
                    </div>
                    <div class="f-review-row is-grand">
                        <span>This order</span>
                        <strong x-text="taka(payable())"></strong>
                    </div>
                    <div class="f-review-row is-old" x-show="previousBaki > 0.009">
                        <span>Previous baki</span>
                        <strong x-text="taka(previousBaki)"></strong>
                    </div>
                    <div class="f-review-row is-grand is-old" x-show="previousBaki > 0.009">
                        <span>Total baki</span>
                        <strong x-text="taka(payable() + Number(previousBaki || 0))"></strong>
                    </div>
                </div>

                <p class="f-review-pay-note">No payment on this order now. Collect when you deliver.</p>

                <label class="f-label" for="order-notes">{{ __('field.talk_notes_label') }} <em class="as-req">*</em></label>
                <textarea id="order-notes" class="f-textarea f-review-note" name="notes" rows="2" maxlength="1000" required placeholder="{{ __('field.talk_notes_placeholder') }}">{{ old('notes') }}</textarea>
                <div class="f-review-foot">
                    <button class="f-btn f-btn-success f-btn-lg f-btn-block" type="submit" :disabled="submitting || count() === 0 || hasBelowMin() || hasOverStock() || discountAmount() > total() + 0.01">
                        <span x-text="submitting ? 'Sending…' : sendLabel()"></span>
                    </button>
                    <button class="f-review-back" type="button" @click="reviewSheet = false">Keep adding products</button>
                </div>
            </form>

        </div>
    @endif

    @if ($visit->isOpen())
        @include('field.partials.leave-sheet', ['visit' => $visit, 'leaveRedirect' => 'shops'])
    @endif
@endsection

@if ($visit->isOpen())
@push('scripts')
<script>
(function () {
    let intentional = false;
    const markIntentional = () => { intentional = true; };

    document.querySelectorAll('form[action*="/visits/{{ $visit->id }}"]').forEach((form) => {
        form.addEventListener('submit', markIntentional);
    });

    document.querySelector('[data-leave-visit]')?.addEventListener('submit', (e) => {
        e.preventDefault();
        window.dispatchEvent(new CustomEvent('open-leave', { detail: 'shops' }));
    });

    // Bottom nav / sidebar / More: collect notes + photo before leaving an open visit.
    document.querySelectorAll('.f-nav a[href], .f-more-item[href]').forEach((link) => {
        link.addEventListener('click', (e) => {
            if (intentional) return;
            e.preventDefault();
            const href = link.getAttribute('href') || '';
            let redirect = 'shops';
            if (href.includes('/field/orders')) redirect = 'orders';
            else if (href.includes('/field/dashboard') || href.endsWith('/field') || href.endsWith('/field/')) redirect = 'dashboard';
            window.dispatchEvent(new CustomEvent('open-leave', { detail: redirect }));
        });
    });
})();
</script>
@endpush
@endif

@if ($taking)
@push('scripts')
<script>
function fieldOrder(catalog) {
    return {
        catalog,
        q: '', cat: '', view: 'categories',
        qty: {}, prices: {},
        discount: 0,
        previousBaki: {{ json_encode($outstanding) }},
        reviewSheet: false, submitting: false,
        taka(n) { return '৳ ' + Math.round(n || 0).toLocaleString('en-IN'); },
        sellPrice(p) {
            const raw = this.prices[p.id];
            if (raw === undefined || raw === null || raw === '') return Number(p.price) || 0;
            return Number(raw);
        },
        setPrice(id, v) {
            const n = parseFloat(v);
            this.prices[id] = isNaN(n) || n < 0 ? 0 : Math.round(n * 100) / 100;
        },
        changed(p) {
            return Math.abs(this.sellPrice(p) - Number(p.price || 0)) >= 0.005;
        },
        belowMin(p) {
            const min = Number(p.min_price || 0);
            return min > 0 && this.sellPrice(p) + 0.001 < min;
        },
        hasBelowMin() {
            return this.lines().some((line) => this.belowMin(line));
        },
        stockOf(id) {
            const p = this.catalog.find((item) => item.id === id);
            return Math.max(0, Number(p?.stock || 0));
        },
        canAdd(id) {
            return (this.qty[id] || 0) < this.stockOf(id);
        },
        hasOverStock() {
            return this.lines().some((line) => line.qty > Number(line.stock || 0));
        },
        categoryCards() {
            const map = {};
            this.catalog.forEach((p) => {
                const name = p.category || 'Other';
                map[name] = (map[name] || 0) + 1;
            });
            return Object.keys(map).sort().map((name) => ({ name, count: map[name] }));
        },
        openCategory(name) {
            this.cat = name;
            this.q = '';
            this.view = 'products';
            this.$nextTick(() => window.lucide && lucide.createIcons());
        },
        showAllProducts() {
            this.cat = '';
            this.q = '';
            this.view = 'products';
            this.$nextTick(() => window.lucide && lucide.createIcons());
        },
        showPicked() {
            this.cat = '__picked';
            this.q = '';
            this.view = 'products';
            this.$nextTick(() => window.lucide && lucide.createIcons());
        },
        backToCategories() {
            this.cat = '';
            this.q = '';
            this.view = 'categories';
            this.$nextTick(() => window.lucide && lucide.createIcons());
        },
        onSearch() {
            if (this.q.trim()) this.view = 'products';
        },
        filtered() {
            const q = this.q.trim().toLowerCase();
            return this.catalog.filter((p) => {
                if (this.cat === '__picked' && !(this.qty[p.id] > 0)) return false;
                if (!q && this.cat && this.cat !== '__picked' && p.category !== this.cat) return false;
                return !q || p.name.toLowerCase().includes(q) || (p.sku || '').toLowerCase().includes(q);
            });
        },
        inc(id) {
            if (! this.canAdd(id)) return;
            this.qty[id] = (this.qty[id] || 0) + 1;
        },
        dec(id) { this.qty[id] = Math.max(0, (this.qty[id] || 0) - 1); },
        set(id, v) {
            const max = this.stockOf(id);
            this.qty[id] = Math.max(0, Math.min(max, parseInt(v, 10) || 0));
        },
        lines() {
            return this.catalog.filter((p) => this.qty[p.id] > 0).map((p) => {
                const sell = this.sellPrice(p);
                return { ...p, qty: this.qty[p.id], sell, changed: Math.abs(sell - Number(p.price || 0)) >= 0.005 };
            });
        },
        count() { return Object.values(this.qty).reduce((a, b) => a + (b || 0), 0); },
        money(n) { return Math.max(0, Math.round((Number(n) || 0) * 100) / 100); },
        total() { return this.money(this.lines().reduce((sum, l) => sum + l.qty * l.sell, 0)); },
        discountAmount() { return Math.min(this.total(), this.money(this.discount)); },
        payable() { return this.money(this.total() - this.discountAmount()); },
        addDiscount(n) { this.discount = this.money(Math.min(this.total(), this.discountAmount() + this.money(n))); },
        addPercent(p) { this.addDiscount(this.total() * p / 100); },
        clearDiscount() { this.discount = 0; },
        sendLabel() { return 'Send for approval · ৳ ' + Math.round(this.payable()).toLocaleString('en-IN'); },
        clampDiscount() {
            if (this.discountAmount() > this.total()) this.discount = this.total();
        },
        init() {
            this.$watch('reviewSheet', (open) => {
                document.body.classList.toggle('f-sheet-open', !!open);
            });
            this.$watch('discount', () => this.clampDiscount());
            this.$nextTick(() => window.lucide && lucide.createIcons());
            this.$watch('view', () => this.$nextTick(() => window.lucide && lucide.createIcons()));
            this.$watch('cat', () => this.$nextTick(() => window.lucide && lucide.createIcons()));
        },
    };
}
</script>
@endpush
@endif
