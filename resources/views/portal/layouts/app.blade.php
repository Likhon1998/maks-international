<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Shop Portal') · {{ $site->siteName() }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}?v={{ @filemtime(public_path('css/admin.css')) }}">
    @include('partials.favicon')
    <script src="https://unpkg.com/lucide@0.469.0"></script>
    <script src="{{ asset('js/portal-shop.js') }}?v={{ @filemtime(public_path('js/portal-shop.js')) }}"></script>
    <style>[x-cloak]{display:none !important}</style>
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('portalShell', () => {
                const stored = localStorage.getItem('bt_portal_sidebar_v1');
                return {
                    isDesktop: window.innerWidth >= 1025,
                    sidebarOpen: window.innerWidth >= 1025,
                    sidebarExpanded: stored !== '0',
                    toggleSidebar() {
                        if (!this.isDesktop) {
                            this.sidebarOpen = !this.sidebarOpen;
                            return;
                        }
                        this.sidebarExpanded = !this.sidebarExpanded;
                        localStorage.setItem('bt_portal_sidebar_v1', this.sidebarExpanded ? '1' : '0');
                        this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
                    },
                    expandSidebar() {
                        this.sidebarExpanded = true;
                        localStorage.setItem('bt_portal_sidebar_v1', '1');
                        this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
                    },
                    onResize() {
                        this.isDesktop = window.innerWidth >= 1025;
                        if (this.isDesktop) this.sidebarOpen = true;
                    },
                };
            });
        });
    </script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.3/dist/cdn.min.js"></script>
</head>
<body x-data="portalShell" @resize.window="onResize()">
    <div class="overlay" :class="sidebarOpen && !isDesktop && 'show'" @click="sidebarOpen = false"></div>
    <div class="app-shell">
        <aside
            class="sidebar"
            :class="{ open: sidebarOpen, compact: isDesktop && !sidebarExpanded }"
            @click.outside="if (!isDesktop) sidebarOpen = false"
        >
            <div class="sidebar-top">
                <a href="{{ route('portal.dashboard') }}" class="sidebar-brand" title="{{ $site->siteName() }}">
                    <x-brand-logo :size="32" show-wordmark wordmark="B2B Portal" />
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
                <a href="{{ route('portal.dashboard') }}" class="nav-item {{ request()->routeIs('portal.dashboard') ? 'active' : '' }}" title="Dashboard">
                    <i data-lucide="layout-dashboard"></i> <span>Dashboard</span>
                </a>
                <a href="{{ route('portal.products') }}" class="nav-item {{ request()->routeIs('portal.products') ? 'active' : '' }}" title="Shop">
                    <i data-lucide="shopping-bag"></i> <span>Shop</span>
                </a>
                <a href="{{ route('portal.orders') }}" class="nav-item {{ request()->routeIs('portal.orders*') ? 'active' : '' }}" title="My orders">
                    <i data-lucide="clipboard-list"></i> <span>My orders</span>
                </a>
                <a href="{{ route('portal.statement') }}" class="nav-item {{ request()->routeIs('portal.statement') ? 'active' : '' }}" title="Account statement">
                    <i data-lucide="file-text"></i> <span>Statement</span>
                </a>
                <a href="{{ route('portal.profile') }}" class="nav-item {{ request()->routeIs('portal.profile') ? 'active' : '' }}" title="My shop">
                    <i data-lucide="store"></i> <span>My shop</span>
                </a>
                <a href="{{ route('portal.account.password') }}" class="nav-item {{ request()->routeIs('portal.account.*') ? 'active' : '' }}" title="Password">
                    <i data-lucide="key-round"></i> <span>Password</span>
                </a>
            </div>
            <div class="sidebar-user">
                <div class="avatar">{{ strtoupper(substr(auth()->user()->name ?? 'SH', 0, 2)) }}</div>
                <div class="sidebar-user-meta" style="min-width:0">
                    <div style="color:#fff;font-weight:600;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ $currentShop->name ?? 'Shop' }}</div>
                    <div style="font-size:11px;color:#8b93a7;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ auth()->user()->name }}</div>
                </div>
                <form action="{{ route('portal.logout') }}" method="post" style="margin-left:auto">@csrf
                    <button type="submit" class="logout-btn" title="Sign out">
                        <i data-lucide="log-out" style="width:16px;height:16px"></i>
                        <span>Sign out</span>
                    </button>
                </form>
            </div>
        </aside>
        <div class="main">
            <header class="topbar ecom-topbar">
                <div class="search-wrap" style="max-width:none;flex:1">
                    <button class="icon-btn" type="button" @click="toggleSidebar()" aria-label="Toggle sidebar" title="Toggle sidebar">
                        <i data-lucide="menu"></i>
                    </button>
                    <div>
                        <div style="font-weight:700">{{ $currentShop->name ?? 'Shop portal' }}</div>
                        <div class="muted">Wholesale shop · your prices · wishlist & cart</div>
                    </div>
                </div>
                @unless(request()->routeIs('portal.products'))
                    <a class="btn btn-primary btn-sm" href="{{ route('portal.products') }}">Open shop</a>
                @endunless
            </header>
            <main class="page">@yield('content')</main>
        </div>
    </div>
    <script>document.addEventListener('DOMContentLoaded', () => window.lucide && lucide.createIcons());</script>
    @stack('scripts')
</body>
</html>
