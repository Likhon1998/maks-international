<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#4C1D95">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="format-detection" content="telephone=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('field.home')) · {{ $site->siteName() }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
    $moreActive = request()->routeIs('field.earnings', 'field.collections', 'field.dues*', 'field.notifications*', 'field.account.*', 'field.visits', 'field.announcements');
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
                    <button type="submit" class="f-back" aria-label="{{ __('field.back') }}" title="{{ __('field.back') }}"><i data-lucide="arrow-left"></i></button>
                </form>
            @elseif ($backHref !== '')
                <a class="f-back" href="{{ $backHref }}" aria-label="{{ __('field.back') }}"><i data-lucide="arrow-left"></i></a>
            @else
                <div class="f-avatar" aria-hidden="true">{{ strtoupper($initials ?: 'S') }}</div>
            @endif
            <div class="f-top-text">
                <h1 class="f-top-title">@yield('heading', __('field.home'))</h1>
                <div class="f-top-sub">@yield('subheading', trim(($user?->name ?? '').($fieldProfile?->employee_code ? ' · '.$fieldProfile->employee_code : '')))</div>
            </div>
            <div class="f-top-actions">
                @include('field.partials.locale-switch', ['class' => 'f-lang-top'])
                @yield('actions')
            </div>
            @include('field.partials.locale-switch', ['class' => 'f-lang-mobile'])
            <a class="f-icon-btn f-desktop-only" href="{{ route('field.account.password') }}" aria-label="{{ __('field.change_password') }}" title="{{ __('field.change_password') }}"><i data-lucide="key-round"></i></a>
            <form class="f-desktop-only" action="{{ route('field.logout') }}" method="post" onsubmit="return confirm(@js(__('field.sign_out_confirm')))">
                @csrf
                <button type="submit" class="f-icon-btn" aria-label="{{ __('field.sign_out') }}" title="{{ __('field.sign_out') }}"><i data-lucide="log-out"></i></button>
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
                        <strong>{{ __('field.you_are_at', ['shop' => $fieldOpenVisit->shop?->name]) }}</strong>
                        <span>{{ __('field.checked_in_ago', ['when' => $fieldOpenVisit->checked_in_at?->diffForHumans()]) }}</span>
                    </span>
                    <a class="f-btn f-btn-warn f-btn-sm" href="{{ route('field.visit.show', $fieldOpenVisit) }}">{{ __('field.continue') }}</a>
                    @include('field.partials.leave-shop', [
                        'leaveVisit' => $fieldOpenVisit,
                        'leaveRedirect' => request()->routeIs('field.dashboard') ? 'dashboard' : (request()->routeIs('field.orders*') ? 'orders' : 'shops'),
                    ])
                </div>
            @endif

            @yield('content')
        </main>

        @yield('bar')

        <nav class="f-nav" aria-label="{{ __('field.menu') }}">
            <a class="f-nav-brand" href="{{ route('field.dashboard') }}" title="{{ $site->siteName() }}">
                <img src="{{ $site->logoUrl() }}" alt="{{ $site->siteName() }}">
                <span><b>{{ $site->siteName() }}</b><small>{{ __('field.app_name') }}</small></span>
            </a>
            <button type="button" class="f-side-toggle" id="f-side-toggle" aria-label="{{ __('field.collapse_sidebar') }}" title="{{ __('field.collapse_sidebar') }}" aria-expanded="true"><i data-lucide="chevron-left"></i></button>
            <span class="f-nav-label">{{ __('field.menu') }}</span>

            <a href="{{ route('field.dashboard') }}" title="{{ __('field.home') }}" class="tone-violet {{ request()->routeIs('field.dashboard') ? 'active' : '' }}"><span class="f-nav-ico"><i data-lucide="layout-dashboard"></i></span><span class="f-nav-text">{{ __('field.home') }}</span></a>
            <a href="{{ route('field.shops') }}" title="{{ __('field.shops') }}" class="tone-blue {{ request()->routeIs('field.shops', 'field.shops.*', 'field.visit.*') ? 'active' : '' }}">
                <span class="f-nav-ico"><i data-lucide="store"></i></span><span class="f-nav-text">{{ __('field.shops') }}</span>
                @if ($fieldOpenVisit)<span class="badge-dot" aria-label="{{ __('field.visit_in_progress') }}">1</span>@endif
            </a>
            <a href="{{ route('field.orders') }}" title="{{ __('field.orders') }}" class="tone-amber {{ request()->routeIs('field.orders*') ? 'active' : '' }}"><span class="f-nav-ico"><i data-lucide="receipt-text"></i></span><span class="f-nav-text">{{ __('field.orders') }}</span></a>
            <a href="{{ route('field.deliveries') }}" title="{{ __('field.deliveries') }}" class="tone-sky {{ request()->routeIs('field.deliveries*') ? 'active' : '' }}"><span class="f-nav-ico"><i data-lucide="truck"></i></span><span class="f-nav-text">{{ __('field.deliveries') }}</span></a>
            <a href="{{ route('field.dues') }}" title="{{ __('field.collect_baki') }}" class="f-nav-desk tone-rose {{ request()->routeIs('field.dues*') ? 'active' : '' }}"><span class="f-nav-ico"><i data-lucide="scale"></i></span><span class="f-nav-text">{{ __('field.collect_baki') }}</span></a>

            <button type="button" class="f-nav-more-btn tone-teal {{ $moreActive ? 'active' : '' }}" @click="moreOpen = true" aria-label="{{ __('field.more') }}">
                <img class="f-nav-more-logo" src="{{ $site->logoUrl() }}" alt="" width="22" height="22">
                <span class="f-nav-text">{{ __('field.more') }}</span>
            </button>

            <a href="{{ route('field.visits') }}" title="{{ __('field.visit_log') }}" class="f-nav-extra tone-blue {{ request()->routeIs('field.visits') ? 'active' : '' }}"><span class="f-nav-ico"><i data-lucide="clipboard-list"></i></span><span class="f-nav-text">{{ __('field.visit_log') }}</span></a>
            <a href="{{ route('field.announcements') }}" title="{{ __('field.news') }}" class="f-nav-extra tone-violet {{ request()->routeIs('field.announcements') ? 'active' : '' }}"><span class="f-nav-ico"><i data-lucide="megaphone"></i></span><span class="f-nav-text">{{ __('field.news') }}</span></a>
            <a href="{{ route('field.earnings') }}" title="{{ __('field.earnings') }}" class="f-nav-extra tone-green {{ request()->routeIs('field.earnings') ? 'active' : '' }}"><span class="f-nav-ico"><i data-lucide="wallet"></i></span><span class="f-nav-text">{{ __('field.earnings') }}</span></a>
            <a href="{{ route('field.collections') }}" title="{{ __('field.collected_money') }}" class="f-nav-extra tone-blue {{ request()->routeIs('field.collections') ? 'active' : '' }}"><span class="f-nav-ico"><i data-lucide="banknote"></i></span><span class="f-nav-text">{{ __('field.collected') }}</span></a>
            <a href="{{ route('field.notifications') }}" title="{{ __('field.alerts') }}" class="f-nav-extra tone-rose {{ request()->routeIs('field.notifications*') ? 'active' : '' }}"><span class="f-nav-ico"><i data-lucide="bell"></i></span><span class="f-nav-text">{{ __('field.alerts') }}</span></a>

            <div class="f-nav-user">
                <div class="f-avatar" aria-hidden="true">{{ strtoupper($initials ?: 'S') }}</div>
                <div class="f-nav-user-text">
                    <b>{{ $user?->name }}</b>
                    @if ($user?->email)
                        <small class="f-nav-email" title="{{ $user->email }}">{{ $user->email }}</small>
                    @endif
                    <small>{{ $fieldProfile?->employee_code ?: __('field.field_salesman') }}</small>
                </div>
                <a class="f-icon-btn" href="{{ route('field.account.password') }}" aria-label="{{ __('field.change_password') }}" title="{{ __('field.change_password') }}"><i data-lucide="key-round"></i></a>
                <form action="{{ route('field.logout') }}" method="post" onsubmit="return confirm(@js(__('field.sign_out_confirm')))">
                    @csrf
                    <button type="submit" class="f-icon-btn" aria-label="{{ __('field.sign_out') }}" title="{{ __('field.sign_out') }}"><i data-lucide="log-out"></i></button>
                </form>
            </div>
        </nav>
    </div>

    {{-- Mobile More sheet --}}
    <div class="f-sheet-backdrop f-more-backdrop" x-show="moreOpen" x-transition.opacity x-cloak @click="moreOpen = false"></div>
    <div class="f-sheet f-more-sheet" x-show="moreOpen" x-transition x-cloak role="dialog" aria-label="{{ __('field.more') }}" @click.stop>
        <div class="f-sheet-grip"></div>
        <div class="f-more-brand">
            <img src="{{ $site->logoUrl() }}" alt="{{ $site->siteName() }}">
            <span>
                <b>{{ $site->siteName() }}</b>
                <small>{{ __('field.app_name') }}</small>
            </span>
        </div>
        <div class="f-more-head">
            <div class="f-avatar" aria-hidden="true">{{ strtoupper($initials ?: 'S') }}</div>
            <div class="f-more-user">
                <strong>{{ $user?->name }}</strong>
                @if ($user?->email)
                    <div class="f-more-email" title="{{ $user->email }}">{{ $user->email }}</div>
                @endif
                <div class="f-muted f-small">{{ $fieldProfile?->employee_code ?: __('field.field_salesman') }}</div>
            </div>
            @include('field.partials.locale-switch', ['class' => 'f-lang-more'])
        </div>
        <div class="f-more-grid">
            <a class="f-more-item" href="{{ route('field.visits') }}" @click="moreOpen = false"><span class="f-ico tone-blue"><i data-lucide="clipboard-list"></i></span>{{ __('field.visit_log') }}</a>
            <a class="f-more-item" href="{{ route('field.announcements') }}" @click="moreOpen = false"><span class="f-ico tone-violet"><i data-lucide="megaphone"></i></span>{{ __('field.news') }}</a>
            <a class="f-more-item" href="{{ route('field.earnings') }}" @click="moreOpen = false"><span class="f-ico tone-green"><i data-lucide="wallet"></i></span>{{ __('field.earnings') }}</a>
            <a class="f-more-item" href="{{ route('field.dues') }}" @click="moreOpen = false"><span class="f-ico tone-rose"><i data-lucide="scale"></i></span>{{ __('field.collect_baki') }}</a>
            <a class="f-more-item" href="{{ route('field.collections') }}" @click="moreOpen = false"><span class="f-ico tone-blue"><i data-lucide="banknote"></i></span>{{ __('field.collected_money') }}</a>
            <a class="f-more-item" href="{{ route('field.notifications') }}" @click="moreOpen = false"><span class="f-ico tone-rose"><i data-lucide="bell"></i></span>{{ __('field.alerts') }}</a>
            <a class="f-more-item" href="{{ route('field.account.password') }}" @click="moreOpen = false"><span class="f-ico tone-amber"><i data-lucide="key-round"></i></span>{{ __('field.password') }}</a>
        </div>
        <form action="{{ route('field.logout') }}" method="post" onsubmit="return confirm(@js(__('field.sign_out_confirm')))" style="margin-top:12px">
            @csrf
            <button class="f-btn f-btn-ghost f-btn-block" type="submit"><i data-lucide="log-out"></i> {{ __('field.sign_out') }}</button>
        </form>
        <button type="button" class="f-btn f-btn-ghost f-btn-block" style="margin-top:8px" @click="moreOpen = false">{{ __('field.close') }}</button>
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
