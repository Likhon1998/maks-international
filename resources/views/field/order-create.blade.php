@extends('field.layouts.app')
@section('title', 'New order')
@section('heading', 'New order')
@section('subheading', 'Select a shop, then add products')
@section('back', route('field.orders'))
@section('content')
    @php
        $byArea = $shops->groupBy('area_label');
        $shopOptions = $shops->map(fn ($shop) => [
            'value' => (string) $shop->id,
            'label' => $shop->name
                .' · '.($shop->owner_name ?: $shop->code)
                .' · '.($shop->area_label ?? 'No area')
                .($shop->is_mine ? ' · My shop' : ''),
            'action' => route('field.shops.order', $shop),
        ])->values()->all();
        $blocked = (bool) $openVisit;
    @endphp

    @if ($errors->any())
        <div class="f-warn" style="margin-bottom:12px">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    @if ($openVisit)
        <div class="f-warn" style="margin-bottom:12px">
            You are at <b>{{ $openVisit->shop?->name }}</b>. Leave that shop first (note and photo), then select another shop for a new order.
            <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
                <a class="f-btn f-btn-primary f-btn-sm" href="{{ route('field.visit.show', $openVisit) }}">
                    <i data-lucide="store"></i> Continue visit
                </a>
                @include('field.partials.leave-shop', [
                    'leaveVisit' => $openVisit,
                    'leaveRedirect' => 'orders',
                    'leaveClass' => 'f-btn f-btn-ghost f-btn-sm',
                ])
            </div>
        </div>
    @endif

    <div
        class="f-order-pick"
        x-data="{
            q: '',
            show: 'all',
            area: '',
            selecting: false,
            shopOpen: false,
            shopQuery: '',
            shopHi: 0,
            shops: @js($shopOptions),
            blocked: @js($blocked),
            get shopFiltered() {
                const q = String(this.shopQuery || '').trim().toLowerCase();
                if (!q) return this.shops;
                return this.shops.filter((shop) => shop.label.toLowerCase().includes(q));
            },
            get shopDisplay() {
                return this.selecting ? 'Opening shop…' : 'Search and select a shop';
            },
            ok(el) {
                const d = el.dataset;
                return (!this.q || (d.search || '').includes(this.q.toLowerCase()))
                    && (this.show === 'all' || this.show === d.owner)
                    && (this.area === '' || d.area === this.area);
            },
            hasVisible(section) {
                return [...section.querySelectorAll('[data-search]')].some((el) => this.ok(el));
            },
            toggleShopPicker() {
                if (this.blocked || this.selecting) return;
                this.shopOpen = !this.shopOpen;
                this.shopQuery = '';
                this.shopHi = 0;
                document.body.classList.toggle('f-combo-lock', this.shopOpen && window.matchMedia('(max-width: 1023.98px)').matches);
                if (this.shopOpen) this.$nextTick(() => {
                    this.$refs.shopQuery?.focus();
                    if (window.lucide) lucide.createIcons();
                });
            },
            closeShopPicker() {
                this.shopOpen = false;
                document.body.classList.remove('f-combo-lock');
            },
            onShopKey(event) {
                const rows = this.shopFiltered;
                if (event.key === 'ArrowDown') { event.preventDefault(); this.shopHi = rows.length ? (this.shopHi + 1) % rows.length : 0; }
                else if (event.key === 'ArrowUp') { event.preventDefault(); this.shopHi = rows.length ? (this.shopHi - 1 + rows.length) % rows.length : 0; }
                else if (event.key === 'Enter') { event.preventDefault(); if (rows[this.shopHi]) this.pickShop(rows[this.shopHi].action); }
                else if (event.key === 'Escape') { event.preventDefault(); this.closeShopPicker(); }
                else { this.shopHi = 0; }
            },
            pickShop(action) {
                if (this.selecting || this.blocked || !action) return;
                this.selecting = true;
                this.closeShopPicker();
                const form = this.$refs.pickForm;
                form.action = action;
                if (typeof form.requestSubmit === 'function') form.requestSubmit();
                else form.submit();
            }
        }"
        @keydown.escape.window="closeShopPicker()"
    >
        <form
            x-ref="pickForm"
            method="post"
            action="#"
            data-checkin
            class="f-order-pick-form"
            @submit="selecting = true"
        >
            @csrf
            <input type="hidden" name="latitude">
            <input type="hidden" name="longitude">
        </form>

        <section class="f-panel" style="margin-bottom:12px">
            <div class="f-panel-head" style="margin-bottom:10px">
                <strong>Select shop</strong>
                <span class="f-muted">{{ $shops->count() }} available</span>
            </div>
            <div
                class="f-combo f-shop-combo"
                @click.outside="closeShopPicker()"
                :class="{ 'is-open': shopOpen, 'is-disabled': selecting || blocked }"
            >
                <div class="f-area-select">
                    <i data-lucide="store"></i>
                    <button type="button" class="f-combo-btn" @click="toggleShopPicker()" aria-label="Select shop" :aria-expanded="shopOpen" :disabled="selecting || blocked">
                        <span class="f-combo-value muted" x-text="shopDisplay"></span>
                    </button>
                    <i data-lucide="chevron-down"></i>
                </div>
                <div class="f-combo-backdrop" x-show="shopOpen" x-cloak @click="closeShopPicker()"></div>
                <div class="f-combo-pop" x-show="shopOpen" x-cloak @click.stop role="dialog" aria-label="Select shop">
                    <div class="f-combo-sheet-head">
                        <span class="f-sheet-grip" aria-hidden="true"></span>
                        <div class="f-combo-sheet-bar">
                            <strong>Select shop</strong>
                            <button type="button" class="f-combo-done" @click="closeShopPicker()">Done</button>
                        </div>
                    </div>
                    <div class="f-combo-search">
                        <i data-lucide="search"></i>
                        <input type="search" x-ref="shopQuery" x-model="shopQuery" placeholder="Shop, owner or area" autocomplete="off" inputmode="search" enterkeyhint="search" @keydown="onShopKey($event)">
                    </div>
                    <ul class="f-combo-list" role="listbox">
                        <template x-for="(item, i) in shopFiltered" :key="item.value">
                            <li>
                                <button
                                    type="button"
                                    :class="{ hi: i === shopHi }"
                                    @click="pickShop(item.action)"
                                    @mouseenter="shopHi = i"
                                    x-text="item.label"
                                ></button>
                            </li>
                        </template>
                        <li x-show="shopFiltered.length === 0"><span class="f-combo-empty">No shop matches</span></li>
                    </ul>
                </div>
            </div>
            <p class="f-muted" style="margin:8px 0 0;font-size:12px">Or choose a shop from the list below.</p>
        </section>

        <div class="f-toolbar">
            <div class="f-search">
                <i data-lucide="search"></i>
                <input type="search" x-model="q" placeholder="Filter shop, owner or area" autocomplete="off" aria-label="Filter shops">
            </div>
            <div class="f-filters" role="tablist">
                <button type="button" class="f-filter" :class="show === 'all' && 'active'" @click="show = 'all'">All <span class="n">{{ $shops->count() }}</span></button>
                <button type="button" class="f-filter" :class="show === 'mine' && 'active'" @click="show = 'mine'">My shops <span class="n">{{ $mineCount }}</span></button>
                <button type="button" class="f-filter" :class="show === 'other' && 'active'" @click="show = 'other'">Not mine <span class="n">{{ $shops->count() - $mineCount }}</span></button>
            </div>
            @include('field.partials.area-select')
        </div>

        @forelse ($byArea as $areaName => $areaShops)
            <section class="f-area f-mobile-only" x-show="hasVisible($el)">
                <div class="f-area-head">
                    <h3><i data-lucide="map-pin"></i> {{ $areaName }}</h3>
                    <span>{{ $areaShops->count() }} {{ \Illuminate\Support\Str::plural('shop', $areaShops->count()) }}</span>
                </div>
                @foreach ($areaShops as $shop)
                    @php
                        $outstanding = (float) $shop->outstanding_balance;
                        $tone = ['violet', 'blue', 'green', 'amber', 'rose', 'sky', 'teal'][crc32((string) $shop->name) % 7];
                        $search = strtolower($shop->name.' '.$shop->code.' '.$shop->owner_name.' '.$shop->city.' '.$shop->territory?->name.' '.($shop->area_label ?? ''));
                        $isOpenHere = $openVisit && (int) $openVisit->shop_id === (int) $shop->id;
                    @endphp
                    <div class="f-shop" data-search="{{ $search }}" data-owner="{{ $shop->is_mine ? 'mine' : 'other' }}" data-area="{{ $shop->area_label }}" x-show="ok($el)">
                        <div class="f-shop-head">
                            <div class="f-shop-ico tone-{{ $tone }}">{{ strtoupper(mb_substr($shop->name, 0, 1)) }}</div>
                            <div style="flex:1;min-width:0">
                                <div class="f-shop-name">{{ $shop->name }}</div>
                                <div class="f-shop-meta">{{ $shop->owner_name ?: $shop->code }}</div>
                                <div class="f-chips">
                                    <span class="f-chip {{ $outstanding > 0 ? 'red' : 'green' }}">Baki {{ \App\Support\DemoData::taka($outstanding) }}</span>
                                    @if ($shop->is_mine)
                                        <span class="f-chip green">My shop</span>
                                    @elseif ($shop->assignedSalesman)
                                        <span class="f-chip">{{ $shop->assignedSalesman->name }}</span>
                                    @else
                                        <span class="f-chip amber">Unassigned</span>
                                    @endif
                                    @if ($shop->priceGroup)<span class="f-chip">{{ $shop->priceGroup->name }}</span>@endif
                                </div>
                            </div>
                        </div>
                        @if ($isOpenHere)
                            <a class="f-btn f-btn-warn f-btn-block" href="{{ route('field.visit.show', $openVisit) }}"><i data-lucide="store"></i> Continue visit</a>
                        @elseif ($openVisit)
                            @include('field.partials.leave-shop', [
                                'leaveVisit' => $openVisit,
                                'leaveRedirect' => 'orders',
                                'leaveClass' => 'f-btn f-btn-ghost f-btn-block',
                                'leaveLabel' => 'Leave '.$openVisit->shop?->name.' first',
                            ])
                        @else
                            <button
                                class="f-btn f-btn-primary f-btn-block"
                                type="button"
                                :disabled="selecting"
                                @click="pickShop(@js(route('field.shops.order', $shop)))"
                            >
                                <i data-lucide="shopping-bag"></i>
                                <span x-text="selecting ? 'Opening…' : 'Select shop'">Select shop</span>
                            </button>
                        @endif
                    </div>
                @endforeach
            </section>
        @empty
            <div class="f-empty">
                <i data-lucide="store"></i>
                <strong>No active shops</strong>
                Ask the office to add shops and assign them to you.
            </div>
        @endforelse

        @if ($byArea->isNotEmpty())
            <section class="f-panel f-desktop-only">
                <div class="f-table-wrap">
                    <table class="f-table f-table-shops">
                        <thead>
                            <tr>
                                <th>Shop</th>
                                <th class="num">Baki</th>
                                <th>Who</th>
                                <th></th>
                            </tr>
                        </thead>
                        @foreach ($byArea as $areaName => $areaShops)
                            <tbody x-show="hasVisible($el)">
                                <tr class="f-area-row">
                                    <td colspan="4">
                                        <span class="f-area-row-title"><i data-lucide="map-pin"></i> {{ $areaName }}</span>
                                        <span class="f-area-row-meta">{{ $areaShops->count() }} {{ \Illuminate\Support\Str::plural('shop', $areaShops->count()) }}</span>
                                    </td>
                                </tr>
                                @foreach ($areaShops as $shop)
                                    @include('field.partials.shop-row', [
                                        'shop' => $shop,
                                        'openVisit' => $openVisit,
                                        'filterable' => true,
                                        'showGroup' => true,
                                        'hideArea' => true,
                                        'hideVisit' => true,
                                        'orderMode' => true,
                                    ])
                                @endforeach
                            </tbody>
                        @endforeach
                    </table>
                </div>
            </section>
        @endif
    </div>

    <div class="f-shop-foot">
        <a class="f-btn f-btn-ghost f-btn-sm" href="{{ route('field.shops.create') }}"><i data-lucide="plus"></i> Add a new shop</a>
        <a class="f-btn f-btn-ghost f-btn-sm" href="{{ route('field.partners.create') }}"><i data-lucide="handshake"></i> Partner application</a>
        <a class="f-btn f-btn-ghost f-btn-sm" href="{{ route('field.shops') }}"><i data-lucide="store"></i> All shops</a>
    </div>
@endsection
