@extends('field.layouts.app')
@section('title', 'Shops')
@section('heading', 'Shops')
@php
    $visited = $shops->where('is_mine', true)->where('visited_today', true)->count();
    $addedPending = $addedShops->where('status', \App\Models\Shop::STATUS_PENDING)->count();
    $addedMonth = $addedShops->filter(fn ($s) => $s->created_at?->gte(now()->startOfMonth()))->count();
    $addedToday = $addedShops->filter(fn ($s) => $s->created_at?->isToday())->count();
    $byArea = $shops->groupBy('area_label');
@endphp
@section('subheading', $mineCount.' assigned to you · '.$otherCount.' not assigned to you')
@section('actions')
    <a class="f-btn f-btn-ghost f-btn-sm" href="{{ route('field.shops.create') }}"><i data-lucide="plus"></i> Add shop</a>
    <a class="f-btn f-btn-ghost f-btn-sm" href="{{ route('field.partners.create') }}"><i data-lucide="handshake"></i> Partner</a>
    <a class="f-btn f-btn-primary f-btn-sm" href="{{ route('field.orders.create') }}"><i data-lucide="shopping-bag"></i> New order</a>
@endsection
@section('content')
    <div class="f-kpis">
        <div class="f-kpi tone-violet">
            <div class="f-kpi-top"><span class="f-ico"><i data-lucide="store"></i></span><span class="f-kpi-label">My shops</span></div>
            <div class="f-kpi-value">{{ $mineCount }}</div>
            <div class="f-kpi-meta">Assigned by admin</div>
        </div>
        <div class="f-kpi tone-blue">
            <div class="f-kpi-top"><span class="f-ico"><i data-lucide="map"></i></span><span class="f-kpi-label">Other shops</span></div>
            <div class="f-kpi-value">{{ $otherCount }}</div>
            <div class="f-kpi-meta">Not on your route</div>
        </div>
        <div class="f-kpi tone-green">
            <div class="f-kpi-top"><span class="f-ico"><i data-lucide="check-circle-2"></i></span><span class="f-kpi-label">Visited today</span></div>
            <div class="f-kpi-value">{{ $visited }}</div>
            <div class="f-kpi-meta">Of your shops</div>
        </div>
        <a class="f-kpi tone-rose" href="#added">
            <div class="f-kpi-top"><span class="f-ico"><i data-lucide="store"></i></span><span class="f-kpi-label">Shops I added</span>@if ($addedPending)<span class="f-kpi-badge">{{ $addedPending }} pending</span>@endif</div>
            <div class="f-kpi-value">{{ $addedShops->count() }}</div>
            <div class="f-kpi-meta"><b>{{ $addedToday }}</b> today · {{ $addedMonth }} this month</div>
        </a>
    </div>

    <a class="f-btn f-btn-primary f-btn-block f-mobile-only" style="margin-bottom:12px" href="{{ route('field.shops.create') }}"><i data-lucide="plus"></i> Add a new shop</a>

    <div x-data="{
        q: '', show: 'all', area: '',
        ok(el) {
            const d = el.dataset;
            return (!this.q || (d.search || '').includes(this.q.toLowerCase()))
                && (this.show === 'all' || this.show === d.owner || this.show === d.state)
                && (this.area === '' || d.area === this.area);
        },
        hasVisible(section) {
            return [...section.querySelectorAll('[data-search]')].some((el) => this.ok(el));
        }
    }">
        <div class="f-toolbar">
            <div class="f-search">
                <i data-lucide="search"></i>
                <input type="search" x-model="q" placeholder="Search shop, owner or area" autocomplete="off" aria-label="Search shops">
            </div>
            <div class="f-filters" role="tablist">
                <button type="button" class="f-filter" :class="show === 'all' && 'active'" @click="show = 'all'">All <span class="n">{{ $shops->count() }}</span></button>
                <button type="button" class="f-filter" :class="show === 'mine' && 'active'" @click="show = 'mine'">My shops <span class="n">{{ $mineCount }}</span></button>
                <button type="button" class="f-filter" :class="show === 'other' && 'active'" @click="show = 'other'">Not mine <span class="n">{{ $otherCount }}</span></button>
                <button type="button" class="f-filter" :class="show === 'todo' && 'active'" @click="show = 'todo'">Not visited</button>
                <button type="button" class="f-filter" :class="show === 'done' && 'active'" @click="show = 'done'">Visited today</button>
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
                    <div x-show="ok($el.firstElementChild)">
                        @include('field.partials.shop-card', ['shop' => $shop, 'openVisit' => $openVisit])
                    </div>
                @endforeach
            </section>
        @empty
            <div class="f-empty">
                <i data-lucide="store"></i>
                <strong>No shops yet</strong>
                Ask the office to add shops and assign them, or <a href="{{ route('field.shops.create') }}">add a shop</a>.
            </div>
        @endforelse

        @if ($byArea->isNotEmpty())
            <section class="f-panel f-desktop-only">
                <div class="f-table-wrap">
                    <table class="f-table f-table-shops">
                        <thead>
                            <tr>
                                <th>Shop</th>
                                <th>Last visit</th>
                                <th class="num">Baki</th>
                                <th>Who</th>
                                <th></th>
                            </tr>
                        </thead>
                        @foreach ($byArea as $areaName => $areaShops)
                            <tbody x-show="hasVisible($el)">
                                <tr class="f-area-row">
                                    <td colspan="5">
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
                                    ])
                                @endforeach
                            </tbody>
                        @endforeach
                    </table>
                </div>
            </section>
        @endif
    </div>

    <section class="f-panel" id="added" style="margin-top:14px">
        <div class="f-panel-head">
            <h2><span class="f-ico tone-rose"><i data-lucide="store"></i></span>Shops I added</h2>
            <a href="{{ route('field.shops.create') }}">+ Add shop</a>
        </div>
        @if ($addedShops->isEmpty())
            <div class="f-panel-body">
                <div class="f-empty">
                    <i data-lucide="store"></i>
                    <strong>No shops added yet</strong>
                    Found a new retailer? Add them and you can take orders immediately.
                </div>
            </div>
        @else
            <div class="f-table-wrap">
                <table class="f-table f-table-added">
                    <thead>
                        <tr>
                            <th class="shop">Shop</th>
                            <th class="f-desktop-only">Area</th>
                            <th>Added</th>
                            <th class="status">Status</th>
                            <th class="act"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($addedShops as $added)
                            @php
                                $tone = match ($added->status) {
                                    \App\Models\Shop::STATUS_ACTIVE => 'green',
                                    \App\Models\Shop::STATUS_REJECTED => 'red',
                                    \App\Models\Shop::STATUS_ON_HOLD => 'gray',
                                    default => 'amber',
                                };
                            @endphp
                            <tr>
                                <td class="shop">
                                    <span class="strong">{{ $added->name }}</span>
                                    <span class="sub">{{ collect([$added->owner_name, $added->code])->filter()->implode(' · ') }}</span>
                                </td>
                                <td class="f-desktop-only">{{ $added->city ?: '—' }}</td>
                                <td class="f-muted nowrap">{{ $added->created_at?->format('d M') }}</td>
                                <td class="status"><span class="f-status {{ $tone }}">{{ $added->status === \App\Models\Shop::STATUS_PENDING ? 'Needs approval' : $added->statusLabel() }}</span></td>
                                <td class="act">
                                    <div class="actions">
                                        @if ($added->status === \App\Models\Shop::STATUS_PENDING)
                                            <form method="post" action="{{ route('field.shops.approve', $added) }}">
                                                @csrf
                                                <button class="f-btn f-btn-primary f-btn-sm" type="submit"><i data-lucide="check"></i> Approve</button>
                                            </form>
                                        @endif
                                        @if ($added->users_count > 0)
                                            <a class="f-btn f-btn-ghost f-btn-sm" href="{{ route('field.shops.login', $added) }}" title="View or reset owner login"><i data-lucide="key-round"></i> Login</a>
                                        @elseif ($added->status !== \App\Models\Shop::STATUS_REJECTED && $added->status !== \App\Models\Shop::STATUS_ON_HOLD)
                                            <a class="f-btn f-btn-primary f-btn-sm" href="{{ route('field.shops.login', $added) }}" title="Create owner login"><i data-lucide="user-plus"></i> Add login</a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
