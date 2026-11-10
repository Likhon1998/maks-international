<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#4C1D95">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="format-detection" content="telephone=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Field') · {{ $site->siteName() }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/field.css') }}?v={{ filemtime(public_path('css/field.css')) }}">
    @include('partials.favicon')
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.3/dist/cdn.min.js"></script>
    <script src="https://unpkg.com/lucide@0.469.0"></script>
    <script>try { if (localStorage.getItem('field.sidebar') === 'mini') document.documentElement.classList.add('f-side-mini'); } catch (e) {}</script>
</head>
@php
    $user = auth()->user();
    $initials = collect(explode(' ', (string) $user?->name))->filter()->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('');
    $onVisitPage = request()->routeIs('field.visit.show') && optional(request()->route('visit'))->id === $fieldOpenVisit?->id;
    $moreActive = request()->routeIs('field.earnings', 'field.collections', 'field.notifications*', 'field.account.*', 'field.visits', 'field.partners*', 'field.announcements');
@endphp
<body class="f-app" x-data="{ moreOpen: false }" @keydown.escape.window="moreOpen = false">
    <div class="f-shell @hasSection('has-bar') has-bar @endif">
        <header class="f-top">
            @php
                $backPost = trim($__env->yieldContent('back_post'));
                $backHref = trim($__env->yieldContent('back'));
            @endphp
            @if ($backPost !== '')
                <form method="post" action="{{ $backPost }}" class="f-back-form" data-leave-visit>
                    @csrf
                    @yield('back_post_fields')
                    <button type="submit" class="f-back" aria-label="Back" title="Save visit and go back"><i data-lucide="arrow-left"></i></button>
                </form>
            @elseif ($backHref !== '')
                <a class="f-back" href="{{ $backHref }}" aria-label="Back"><i data-lucide="arrow-left"></i></a>
            @else
                <div class="f-avatar" aria-hidden="true">{{ strtoupper($initials ?: 'S') }}</div>
            @endif
            <div class="f-top-text">
                <h1 class="f-top-title">@yield('heading', 'Field')</h1>
                <div class="f-top-sub">@yield('subheading', trim(($user?->name ?? '').($fieldProfile?->employee_code ? ' · '.$fieldProfile->employee_code : '')))</div>
            </div>
            <div class="f-top-actions">@yield('actions')</div>
            <a class="f-icon-btn f-desktop-only" href="{{ route('field.account.password') }}" aria-label="Change password" title="Change password"><i data-lucide="key-round"></i></a>
            <form class="f-desktop-only" action="{{ route('field.logout') }}" method="post" onsubmit="return confirm('Sign out of the field app?')">
                @csrf
                <button type="submit" class="f-icon-btn" aria-label="Sign out" title="Sign out"><i data-lucide="log-out"></i></button>
            </form>
        </header>

        <main class="f-body">
            @if (session('success'))
                <div class="f-flash ok" role="status"><i data-lucide="check-circle-2"></i><span>{{ session('success') }}</span></div>
            @endif
            @if (session('error'))
                <div class="f-flash err" role="alert"><i data-lucide="alert-circle"></i><span>{{ session('error') }}</span></div>
            @endif
            @if ($errors->any())
                <div class="f-flash err" role="alert"><i data-lucide="alert-circle"></i><span>{{ $errors->first() }}</span></div>
            @endif

            @if ($fieldOpenVisit && ! $onVisitPage)
                <div class="f-resume">
                    <span class="dot" aria-hidden="true"></span>
                    <span class="f-resume-text">
                        <strong>You're at {{ $fieldOpenVisit->shop?->name }}</strong>
                        <span>Checked in {{ $fieldOpenVisit->checked_in_at?->diffForHumans() }} — continue the order, or leave the shop</span>
                    </span>
                    <a class="f-btn f-btn-warn f-btn-sm" href="{{ route('field.visit.show', $fieldOpenVisit) }}">Continue</a>
                    @include('field.partials.leave-shop', [
                        'leaveVisit' => $fieldOpenVisit,
                        'leaveRedirect' => request()->routeIs('field.dashboard') ? 'dashboard' : (request()->routeIs('field.orders*') ? 'orders' : 'shops'),
                    ])
                </div>
            @endif

            @yield('content')
        </main>

        @yield('bar')

        <nav class="f-nav" aria-label="Main">
            <a class="f-nav-brand" href="{{ route('field.dashboard') }}" title="{{ $site->siteName() }}">
                <img src="{{ $site->logoUrl() }}" alt="{{ $site->siteName() }}">
                <span><b>{{ $site->siteName() }}</b><small>Field sales app</small></span>
            </a>
            <button type="button" class="f-side-toggle" id="f-side-toggle" aria-label="Collapse sidebar" title="Collapse sidebar" aria-expanded="true"><i data-lucide="chevron-left"></i></button>
            <span class="f-nav-label">Menu</span>

            <a href="{{ route('field.dashboard') }}" title="Home" class="tone-violet {{ request()->routeIs('field.dashboard') ? 'active' : '' }}"><span class="f-nav-ico"><i data-lucide="layout-dashboard"></i></span><span class="f-nav-text">Home</span></a>
            <a href="{{ route('field.shops') }}" title="Shops" class="tone-blue {{ request()->routeIs('field.shops', 'field.shops.*', 'field.visit.*') ? 'active' : '' }}">
                <span class="f-nav-ico"><i data-lucide="store"></i></span><span class="f-nav-text">Shops</span>
                @if ($fieldOpenVisit)<span class="badge-dot" aria-label="Visit in progress">1</span>@endif
            </a>
            <a href="{{ route('field.orders') }}" title="Orders" class="tone-amber {{ request()->routeIs('field.orders*') ? 'active' : '' }}"><span class="f-nav-ico"><i data-lucide="receipt-text"></i></span><span class="f-nav-text">Orders</span></a>
            <a href="{{ route('field.deliveries') }}" title="Deliveries" class="tone-sky {{ request()->routeIs('field.deliveries') ? 'active' : '' }}"><span class="f-nav-ico"><i data-lucide="truck"></i></span><span class="f-nav-text">Deliveries</span></a>

            <button type="button" class="f-nav-more-btn tone-teal {{ $moreActive ? 'active' : '' }}" @click="moreOpen = true" aria-label="More menu">
                <img class="f-nav-more-logo" src="{{ $site->logoUrl() }}" alt="" width="22" height="22">
                <span class="f-nav-text">More</span>
            </button>

            <a href="{{ route('field.visits') }}" title="Visit log" class="f-nav-extra tone-blue {{ request()->routeIs('field.visits') ? 'active' : '' }}"><span class="f-nav-ico"><i data-lucide="clipboard-list"></i></span><span class="f-nav-text">Visit log</span></a>
            <a href="{{ route('field.partners') }}" title="Partners" class="f-nav-extra tone-teal {{ request()->routeIs('field.partners*') ? 'active' : '' }}"><span class="f-nav-ico"><i data-lucide="handshake"></i></span><span class="f-nav-text">Partners</span></a>
            <a href="{{ route('field.announcements') }}" title="News" class="f-nav-extra tone-violet {{ request()->routeIs('field.announcements') ? 'active' : '' }}"><span class="f-nav-ico"><i data-lucide="megaphone"></i></span><span class="f-nav-text">News</span></a>
            <a href="{{ route('field.earnings') }}" title="Earnings" class="f-nav-extra tone-green {{ request()->routeIs('field.earnings') ? 'active' : '' }}"><span class="f-nav-ico"><i data-lucide="wallet"></i></span><span class="f-nav-text">Earnings</span></a>
            <a href="{{ route('field.collections') }}" title="Collected money" class="f-nav-extra tone-blue {{ request()->routeIs('field.collections') ? 'active' : '' }}"><span class="f-nav-ico"><i data-lucide="banknote"></i></span><span class="f-nav-text">Collected</span></a>
            <a href="{{ route('field.notifications') }}" title="Notifications" class="f-nav-extra tone-rose {{ request()->routeIs('field.notifications*') ? 'active' : '' }}"><span class="f-nav-ico"><i data-lucide="bell"></i></span><span class="f-nav-text">Alerts</span></a>

            <div class="f-nav-user">
                <div class="f-avatar" aria-hidden="true">{{ strtoupper($initials ?: 'S') }}</div>
                <div class="f-nav-user-text">
                    <b>{{ $user?->name }}</b>
                    <small>{{ $fieldProfile?->employee_code ?: 'Field salesman' }}</small>
                </div>
                <a class="f-icon-btn" href="{{ route('field.account.password') }}" aria-label="Change password" title="Change password"><i data-lucide="key-round"></i></a>
                <form action="{{ route('field.logout') }}" method="post" onsubmit="return confirm('Sign out of the field app?')">
                    @csrf
                    <button type="submit" class="f-icon-btn" aria-label="Sign out" title="Sign out"><i data-lucide="log-out"></i></button>
                </form>
            </div>
        </nav>
    </div>

    {{-- Mobile More sheet --}}
    <div class="f-sheet-backdrop f-more-backdrop" x-show="moreOpen" x-transition.opacity x-cloak @click="moreOpen = false"></div>
    <div class="f-sheet f-more-sheet" x-show="moreOpen" x-transition x-cloak role="dialog" aria-label="More" @click.stop>
        <div class="f-sheet-grip"></div>
        <div class="f-more-brand">
            <img src="{{ $site->logoUrl() }}" alt="{{ $site->siteName() }}">
            <span>
                <b>{{ $site->siteName() }}</b>
                <small>Field sales app</small>
            </span>
        </div>
        <div class="f-more-head">
            <div class="f-avatar" aria-hidden="true">{{ strtoupper($initials ?: 'S') }}</div>
            <div>
                <strong>{{ $user?->name }}</strong>
                <div class="f-muted f-small">{{ $fieldProfile?->employee_code ?: 'Field salesman' }}</div>
            </div>
        </div>
        <div class="f-more-grid">
            <a class="f-more-item" href="{{ route('field.visits') }}" @click="moreOpen = false"><span class="f-ico tone-blue"><i data-lucide="clipboard-list"></i></span>Visit log</a>
            <a class="f-more-item" href="{{ route('field.partners') }}" @click="moreOpen = false"><span class="f-ico tone-teal"><i data-lucide="handshake"></i></span>Partners</a>
            <a class="f-more-item" href="{{ route('field.announcements') }}" @click="moreOpen = false"><span class="f-ico tone-violet"><i data-lucide="megaphone"></i></span>News</a>
            <a class="f-more-item" href="{{ route('field.earnings') }}" @click="moreOpen = false"><span class="f-ico tone-green"><i data-lucide="wallet"></i></span>Earnings</a>
            <a class="f-more-item" href="{{ route('field.collections') }}" @click="moreOpen = false"><span class="f-ico tone-blue"><i data-lucide="banknote"></i></span>Collected money</a>
            <a class="f-more-item" href="{{ route('field.notifications') }}" @click="moreOpen = false"><span class="f-ico tone-rose"><i data-lucide="bell"></i></span>Alerts</a>
            <a class="f-more-item" href="{{ route('field.account.password') }}" @click="moreOpen = false"><span class="f-ico tone-amber"><i data-lucide="key-round"></i></span>Password</a>
        </div>
        <form action="{{ route('field.logout') }}" method="post" onsubmit="return confirm('Sign out of the field app?')" style="margin-top:12px">
            @csrf
            <button class="f-btn f-btn-ghost f-btn-block" type="submit"><i data-lucide="log-out"></i> Sign out</button>
        </form>
        <button type="button" class="f-btn f-btn-ghost f-btn-block" style="margin-top:8px" @click="moreOpen = false">Close</button>
    </div>

    <script>
        function searchCombo(opts) {
            const items = opts.items || [];
            const placeholder = opts.placeholder || 'Select';
            return {
                open: false,
                query: '',
                hi: 0,
                value: '',
                items,
                init() {
                    this.$watch('open', (on) => this.lock(on));
                },
                get filtered() {
                    const q = String(this.query || '').trim().toLowerCase();
                    if (!q) return this.items;
                    return this.items.filter((item) => item.label.toLowerCase().includes(q) || String(item.value).toLowerCase().includes(q));
                },
                get display() {
                    const hit = this.items.find((item) => String(item.value) === String(this.value));
                    return hit ? hit.label : placeholder;
                },
                isPhone() {
                    return window.matchMedia('(max-width: 1023.98px)').matches;
                },
                lock(on) {
                    document.body.classList.toggle('f-combo-lock', !!on && this.isPhone());
                },
                toggle() {
                    this.open = !this.open;
                    this.query = '';
                    this.hi = 0;
                    this.lock(this.open);
                    if (this.open) this.$nextTick(() => {
                        if (! this.isPhone()) this.$refs.query?.focus();
                        if (window.lucide) lucide.createIcons();
                    });
                },
                pick(value) {
                    this.value = value;
                    this.open = false;
                    this.query = '';
                    this.lock(false);
                },
                onKey(event) {
                    const rows = this.filtered;
                    if (event.key === 'ArrowDown') { event.preventDefault(); this.hi = rows.length ? (this.hi + 1) % rows.length : 0; }
                    else if (event.key === 'ArrowUp') { event.preventDefault(); this.hi = rows.length ? (this.hi - 1 + rows.length) % rows.length : 0; }
                    else if (event.key === 'Enter') { event.preventDefault(); if (rows[this.hi]) this.pick(rows[this.hi].value); }
                    else if (event.key === 'Escape') { event.preventDefault(); this.open = false; this.lock(false); }
                    else { this.hi = 0; }
                },
            };
        }

        document.addEventListener('DOMContentLoaded', () => window.lucide && lucide.createIcons());

        (() => {
            const btn = document.getElementById('f-side-toggle');
            if (!btn) return;
            const sync = () => {
                const mini = document.documentElement.classList.contains('f-side-mini');
                const label = mini ? 'Expand sidebar' : 'Collapse sidebar';
                btn.setAttribute('aria-expanded', String(!mini));
                btn.setAttribute('aria-label', label);
                btn.title = label;
            };
            sync();
            btn.addEventListener('click', () => {
                const mini = document.documentElement.classList.toggle('f-side-mini');
                try { localStorage.setItem('field.sidebar', mini ? 'mini' : 'full'); } catch (e) {}
                sync();
            });
        })();

        // Attach GPS coordinates to check-in forms when the browser allows it; never block the check-in.
        document.addEventListener('submit', (e) => {
            const form = e.target;
            if (!form.matches('[data-checkin]') || form.dataset.located) return;
            e.preventDefault();
            const btn = form.querySelector('button[type=submit]');
            if (btn) {
                btn.disabled = true;
                btn.dataset.label = btn.innerHTML;
                const ordering = form.action.includes('/order');
                btn.innerHTML = ordering ? 'Opening…' : 'Checking in…';
            }
            const go = () => { form.dataset.located = '1'; form.submit(); };
            if (!navigator.geolocation) return go();
            const timer = setTimeout(go, 5000);
            navigator.geolocation.getCurrentPosition((pos) => {
                clearTimeout(timer);
                form.querySelector('[name=latitude]').value = pos.coords.latitude.toFixed(7);
                form.querySelector('[name=longitude]').value = pos.coords.longitude.toFixed(7);
                go();
            }, () => { clearTimeout(timer); go(); }, { enableHighAccuracy: true, timeout: 4500, maximumAge: 60000 });
        });
    </script>
    @stack('scripts')
</body>
</html>
