@php
    $authUser = auth()->user();
    $nav = [
        ['label' => 'SALES & ORDERS', 'items' => [
            ['name' => 'shops.index', 'label' => 'Shops', 'icon' => 'store'],
            ['name' => 'partner-inquiries.index', 'label' => 'Partner applications', 'icon' => 'handshake', 'permission' => 'shops.view'],
            ['name' => 'salesmen.index', 'label' => 'Salesmen', 'icon' => 'users', 'permission' => 'salesmen.view'],
            ['name' => 'orders.index', 'label' => 'Orders', 'icon' => 'shopping-bag', 'permission' => 'orders.view'],
            ['name' => 'fulfilment.index', 'label' => 'Warehouse desk', 'icon' => 'clipboard-check', 'permission' => 'fulfilment.view'],
            ['name' => 'deliveries.index', 'label' => 'Deliveries', 'icon' => 'truck', 'permission' => 'deliveries.view'],
            ['name' => 'deliveries.men', 'label' => 'Delivery men', 'icon' => 'bike', 'permission' => 'deliveries.view'],
            ['name' => 'returns.index', 'label' => 'Returns', 'icon' => 'rotate-ccw'],
            ['name' => 'invoices.index', 'label' => 'Invoices', 'icon' => 'file-text'],
            ['name' => 'payments.index', 'label' => 'Payments', 'icon' => 'wallet'],
            ['name' => 'dues.index', 'label' => 'Due Management', 'icon' => 'scale', 'permission' => 'dues.view'],
        ]],
        ['label' => 'FIELD PERFORMANCE', 'items' => [
            ['name' => 'visits.index', 'label' => 'Field activity', 'icon' => 'map-pin', 'permission' => 'visits.view'],
            ['name' => 'targets.index', 'label' => 'Targets', 'icon' => 'target', 'permission' => 'targets.view'],
            ['name' => 'commissions.index', 'label' => 'Commissions', 'icon' => 'percent', 'permission' => 'commissions.view'],
            ['name' => 'rewards.index', 'label' => 'Rewards', 'icon' => 'gift', 'permission' => 'rewards.view'],
            ['name' => 'salaries.index', 'label' => 'Salaries', 'icon' => 'banknote', 'permission' => 'salaries.view'],
            ['name' => 'announcements.index', 'label' => 'Field alerts', 'icon' => 'megaphone', 'permission' => 'announcements.view'],
        ]],
        ['label' => 'PRODUCT & INVENTORY', 'items' => [
            ['name' => 'products.index', 'label' => 'Products', 'icon' => 'package'],
            ['name' => 'offers.index', 'label' => 'Offers', 'icon' => 'tag', 'permission' => 'products.edit'],
            ['name' => 'categories.index', 'label' => 'Categories', 'icon' => 'layers', 'permission' => 'categories.view'],
            ['name' => 'brands.index', 'label' => 'Brands', 'icon' => 'badge-check', 'permission' => 'brands.view'],
            ['name' => 'inventory.index', 'label' => 'Inventory', 'icon' => 'boxes', 'permission' => 'inventory.view'],
            ['name' => 'warehouses.index', 'label' => 'Warehouses', 'icon' => 'warehouse', 'permission' => 'warehouses.view'],
            ['name' => 'shipments.index', 'label' => 'Shipments', 'icon' => 'ship', 'permission' => 'shipments.view'],
        ]],
        ['label' => 'PROCUREMENT', 'items' => [
            ['name' => 'suppliers.index', 'label' => 'Suppliers', 'icon' => 'factory', 'permission' => 'suppliers.view'],
            ['name' => 'purchases.index', 'label' => 'Purchases', 'icon' => 'clipboard-list', 'permission' => 'purchases.view'],
        ]],
        ['label' => 'REPORTS & ANALYTICS', 'items' => [
            ['name' => 'reports.index', 'label' => 'Reports', 'icon' => 'file-bar-chart', 'permission' => 'reports.view'],
            ['name' => 'analytics.index', 'label' => 'Analytics', 'icon' => 'trending-up', 'permission' => 'analytics.view'],
            ['name' => 'notifications.index', 'label' => 'Notifications', 'icon' => 'bell'],
        ]],
        ['label' => 'SETTINGS', 'items' => [
            ['name' => 'website.edit', 'label' => 'Website', 'icon' => 'globe', 'permission' => 'website.view'],
            ['name' => 'users.index', 'label' => 'Users', 'icon' => 'user-cog', 'permission' => 'users.view'],
            ['name' => 'roles.index', 'label' => 'Roles & Permissions', 'icon' => 'shield', 'permission' => 'roles.view'],
            ['name' => 'price-groups.index', 'label' => 'Price Groups', 'icon' => 'tags', 'permission' => 'price_groups.view'],
            ['name' => 'audit.index', 'label' => 'Audit Logs', 'icon' => 'scroll-text', 'permission' => 'audit.view'],
            ['name' => 'settings.index', 'label' => 'Settings', 'icon' => 'settings', 'permission' => 'settings.view'],
        ]],
    ];
@endphp

<aside
    class="sidebar"
    :class="{ open: sidebarOpen, compact: isDesktop && !sidebarExpanded }"
    @click.outside="if (!isDesktop) sidebarOpen = false"
>
    <div class="sidebar-top">
        <a href="{{ route('dashboard') }}" class="sidebar-brand" title="Bynnas Trade">
            <x-brand-logo :size="32" show-wordmark />
        </a>
        <button
            type="button"
            class="sidebar-expand-btn"
            x-show="isDesktop && !sidebarExpanded"
            x-cloak
            @click="expandSidebar()"
            title="Expand sidebar"
            aria-label="Expand sidebar"
        >
            <i data-lucide="chevrons-right"></i>
        </button>
    </div>

    <div class="nav-scroll">
        <a href="{{ route('dashboard') }}" class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}" title="Dashboard">
            <i data-lucide="layout-dashboard"></i> <span>Dashboard</span>
        </a>

        @foreach ($nav as $group)
            @php
                $visibleItems = collect($group['items'])->filter(
                    fn ($item) => empty($item['permission']) || auth()->user()?->can($item['permission'])
                )->values();
                $groupActive = $visibleItems->contains(
                    fn ($item) => request()->routeIs(explode('.', $item['name'])[0].'.*')
                );
                $groupKey = \Illuminate\Support\Str::slug($group['label']);
            @endphp
            @continue($visibleItems->isEmpty())

            <div
                class="nav-group"
                x-data="{
                    open: {{ $groupActive ? 'true' : 'false' }},
                    key: 'bt_nav_{{ $groupKey }}',
                    init() {
                        if (!{{ $groupActive ? 'true' : 'false' }}) {
                            const stored = localStorage.getItem(this.key);
                            if (stored !== null) this.open = stored === '1';
                        }
                        this.$watch('open', (v) => localStorage.setItem(this.key, v ? '1' : '0'));
                    },
                    toggle() {
                        this.open = !this.open;
                        this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
                    }
                }"
            >
                <button
                    type="button"
                    class="nav-group-toggle"
                    :class="{ open, active: {{ $groupActive ? 'true' : 'false' }} }"
                    @click="toggle()"
                    :aria-expanded="open.toString()"
                >
                    <span>{{ $group['label'] }}</span>
                    <i data-lucide="chevron-down" class="nav-group-chevron"></i>
                </button>

                <div class="nav-group-panel" :class="{ open: open || (isDesktop && !sidebarExpanded) }">
                    <div class="nav-group-items">
                        @foreach ($visibleItems as $item)
                            <a
                                href="{{ route($item['name']) }}"
                                class="nav-item {{ request()->routeIs(explode('.', $item['name'])[0].'.*') ? 'active' : '' }}"
                                title="{{ $item['label'] }}"
                            >
                                <i data-lucide="{{ $item['icon'] }}"></i>
                                <span>{{ $item['label'] }}</span>
                                @if (!empty($item['badge']))
                                    <span class="badge-new">{{ $item['badge'] }}</span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="sidebar-user">
        <div class="avatar">{{ strtoupper(substr($authUser?->name ?? 'SA', 0, 2)) }}</div>
        <div class="sidebar-user-meta" style="min-width:0">
            <div style="color:#fff;font-weight:600;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ $authUser?->name ?? 'Admin' }}</div>
            <div style="font-size:11px;color:#8b93a7;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ $authUser?->roles?->first()?->name ?? 'Administrator' }}</div>
        </div>
        <a href="{{ route('account.password') }}" class="logout-btn account-link" title="Change password" style="margin-left:auto;margin-right:4px">
            <i data-lucide="key-round" style="width:16px;height:16px"></i>
        </a>
        <form action="{{ route('logout') }}" method="post">
            @csrf
            <button type="submit" class="logout-btn" title="Sign out">
                <i data-lucide="log-out" style="width:16px;height:16px"></i>
                <span>Sign out</span>
            </button>
        </form>
    </div>
</aside>
