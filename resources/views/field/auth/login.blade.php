<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#6D4AFF">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sales team login · {{ $site->siteName() }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @include('partials.favicon')
    <style>
        :root {
            --ink: #0F1030;
            --text: #2B2C45;
            --muted: #6E6F86;
            --faint: #A3A4B8;
            --line: #E7E6F0;
            --brand: #6D4AFF;
            --brand-2: #8B5CF6;
            --brand-deep: #5B34E6;
            --brand-soft: #F1EEFF;
            --bg: #F7F7FB;
        }
        *, *::before, *::after { box-sizing: border-box; }
        html, body { margin: 0; height: 100%; overflow: hidden; }
        body {
            height: 100vh;
            height: 100dvh;
            font-family: 'Montserrat', system-ui, -apple-system, 'Segoe UI', sans-serif;
            color: var(--text);
            background: var(--bg);
            -webkit-font-smoothing: antialiased;
        }
        a { color: inherit; }
        svg { display: block; }

        /* Decorative shapes */
        .lg-deco { position: fixed; inset: 0; pointer-events: none; overflow: hidden; z-index: 0; }
        .lg-ring { position: absolute; right: -150px; top: -170px; width: 460px; height: 460px; border-radius: 50%; border: 90px solid #EFEDFA; }
        .lg-blob { position: absolute; left: -170px; bottom: -210px; width: 480px; height: 480px; border-radius: 50%; background: #F0EEF8; }
        .lg-glow { position: absolute; right: 18%; top: 30%; width: 420px; height: 420px; border-radius: 50%; background: radial-gradient(circle, rgba(139,92,246,.08), transparent 70%); }

        /* Page — one viewport, no page scroll */
        .lg-page { position: relative; z-index: 1; height: 100%; max-width: 1240px; margin: 0 auto; padding: 28px 48px 18px; display: flex; flex-direction: column; min-height: 0; overflow: hidden; }
        .lg-brand { display: inline-flex; align-items: center; gap: 12px; text-decoration: none; flex-shrink: 0; }
        .lg-brand img { width: 36px; height: 36px; object-fit: contain; background: #fff; border-radius: 50%; padding: 2px; box-sizing: border-box; }
        .lg-brand b { display: block; font-size: 15px; font-weight: 700; color: var(--ink); letter-spacing: -.01em; }
        .lg-brand small { display: block; font-size: 11px; font-weight: 500; color: var(--muted); margin-top: 1px; }

        .lg-grid { flex: 1; min-height: 0; display: grid; grid-template-columns: minmax(0, 1fr) minmax(340px, 400px); grid-template-areas: "copy card"; column-gap: clamp(36px, 6vw, 96px); align-items: center; padding: 12px 0; }
        .lg-copy { grid-area: copy; display: flex; flex-direction: column; justify-content: center; gap: clamp(16px, 3vh, 32px); min-height: 0; }
        .lg-intro { min-width: 0; }
        .lg-card { grid-area: card; align-self: center; }

        .lg-pill { display: inline-flex; align-items: center; gap: 7px; padding: 5px 10px; border-radius: 8px; background: var(--brand-soft); color: var(--brand); font-size: 11.5px; font-weight: 600; }
        .lg-pill svg { width: 13px; height: 13px; }
        .lg-title { margin: 10px 0 10px; font-size: clamp(28px, 3.4vw, 44px); line-height: 1.08; font-weight: 800; letter-spacing: -.035em; color: var(--ink); }
        .lg-title span { display: block; background: linear-gradient(90deg, #7C5CFF 0%, #6D4AFF 45%, #8B5CF6 100%); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .lg-lead { margin: 0; max-width: 420px; font-size: 14px; line-height: 1.5; color: var(--muted); }

        .lg-feats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); max-width: 500px; }
        .lg-feat { padding: 0 16px; border-left: 1px solid var(--line); }
        .lg-feat:first-child { padding-left: 0; border-left: 0; }
        .lg-feat i { width: 32px; height: 32px; border-radius: 10px; display: grid; place-items: center; background: var(--brand-soft); color: var(--brand); margin-bottom: 10px; }
        .lg-feat i svg { width: 15px; height: 15px; }
        .lg-feat b { display: block; font-size: 12.5px; font-weight: 700; color: var(--ink); margin-bottom: 4px; }
        .lg-feat small { display: block; font-size: 11.5px; line-height: 1.45; color: var(--muted); }

        /* Card */
        .lg-card { width: 100%; background: #fff; border: 1px solid #EEEDF5; border-radius: 18px; padding: 22px 24px 16px; box-shadow: 0 1px 2px rgba(20,16,60,.04), 0 24px 48px -22px rgba(40,30,110,.18); }
        .lg-card-brand { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; }
        .lg-card-brand img { width: 34px; height: 34px; object-fit: contain; background: #fff; border-radius: 50%; padding: 2px; box-sizing: border-box; }
        .lg-card-brand b { display: block; font-size: 14px; font-weight: 700; color: var(--ink); }
        .lg-card-brand small { display: block; font-size: 11px; font-weight: 500; color: var(--muted); margin-top: 1px; }
        .lg-card h1 { margin: 0 0 4px; font-size: 22px; font-weight: 800; letter-spacing: -.03em; color: var(--ink); }
        .lg-sub { margin: 0 0 16px; font-size: 12.5px; color: var(--muted); }

        .lg-label { display: block; font-size: 12px; font-weight: 600; color: var(--ink); margin: 0 0 6px; }
        .lg-field { position: relative; margin-bottom: 12px; }
        .lg-field > svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; color: #8E8FA6; pointer-events: none; transition: color .2s; }
        .lg-input { width: 100%; height: 44px; padding: 0 14px 0 42px; border: 1px solid var(--line); border-radius: 10px; background: #fff; font: 500 14px 'Montserrat', system-ui, sans-serif; color: var(--ink); outline: none; transition: border-color .2s, box-shadow .2s, background .2s; }
        .lg-input::placeholder { color: var(--faint); font-weight: 500; }
        .lg-input:hover { border-color: #D6D4E6; }
        .lg-input:focus { border-color: #9C8CFF; background: #F8F7FF; box-shadow: 0 0 0 4px rgba(109,74,255,.12); }
        .lg-field:focus-within > svg { color: var(--brand); }
        .lg-input.has-eye { padding-right: 48px; }
        .lg-eye { position: absolute; right: 5px; top: 50%; transform: translateY(-50%); width: 38px; height: 38px; border: 0; border-radius: 8px; background: transparent; color: #8E8FA6; display: grid; place-items: center; cursor: pointer; }
        .lg-eye:hover { background: #F3F2F9; color: var(--ink); }
        .lg-eye svg { width: 17px; height: 17px; }
        .lg-eye .off, .lg-eye[aria-pressed="true"] .on { display: none; }
        .lg-eye[aria-pressed="true"] .off { display: block; }
        .lg-caps { display: none; margin: -10px 0 14px; font-size: 12px; font-weight: 600; color: #B45309; }
        .lg-caps.show { display: block; }

        .lg-row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 6px 12px; margin: 0 0 14px; font-size: 12.5px; }
        .lg-remember { display: inline-flex; align-items: center; gap: 9px; color: var(--text); font-weight: 500; cursor: pointer; user-select: none; }
        .lg-remember input { width: 16px; height: 16px; margin: 0; accent-color: var(--brand); }
        .lg-forgot { border: 0; background: none; padding: 4px 0; font: 600 12.5px 'Montserrat', system-ui, sans-serif; color: var(--brand); cursor: pointer; }
        .lg-forgot:hover { color: var(--brand-deep); text-decoration: underline; }
        .lg-note { display: none; margin: -8px 0 18px; padding: 10px 12px; border-radius: 10px; background: var(--brand-soft); color: #4C2FC9; font-size: 12.5px; line-height: 1.5; }
        .lg-note.show { display: block; }

        .lg-submit { position: relative; display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%; height: 44px; border: 0; border-radius: 10px; background: linear-gradient(90deg, #7C5CFF 0%, #6D4AFF 55%, #5B34E6 100%); color: #fff; font: 600 14.5px 'Montserrat', system-ui, sans-serif; cursor: pointer; box-shadow: 0 10px 22px -10px rgba(109,74,255,.7); transition: transform .15s, box-shadow .2s, filter .2s; }
        .lg-submit:hover { filter: brightness(1.05); box-shadow: 0 14px 26px -10px rgba(109,74,255,.8); transform: translateY(-1px); }
        .lg-submit:active { transform: none; }
        .lg-submit:focus-visible { outline: 3px solid rgba(109,74,255,.35); outline-offset: 2px; }
        .lg-submit .arrow { width: 17px; height: 17px; transition: transform .2s; }
        .lg-submit:hover .arrow { transform: translateX(3px); }
        .lg-submit.is-loading { pointer-events: none; opacity: .88; }
        .lg-submit.is-loading .arrow { display: none; }
        .lg-spinner { display: none; width: 16px; height: 16px; border: 2px solid rgba(255,255,255,.4); border-top-color: #fff; border-radius: 50%; animation: lg-spin .7s linear infinite; }
        .lg-submit.is-loading .lg-spinner { display: inline-block; }

        .lg-help { display: flex; align-items: center; gap: 12px; margin-top: 16px; font-size: 11.5px; color: var(--muted); white-space: nowrap; }
        .lg-help::before, .lg-help::after { content: ""; flex: 1; height: 1px; background: var(--line); }

        .lg-error { display: flex; gap: 9px; align-items: flex-start; margin-bottom: 16px; padding: 11px 12px; border-radius: 10px; background: #FEF2F2; border: 1px solid #FBD5D5; color: #B91C1C; font-size: 12.5px; font-weight: 500; line-height: 1.45; animation: lg-shake .45s ease both; }
        .lg-error svg { width: 16px; height: 16px; flex: none; margin-top: 1px; }

        .lg-foot { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px 16px; font-size: 11.5px; color: var(--faint); flex-shrink: 0; }
        .lg-foot nav { display: flex; gap: 16px; }
        .lg-foot a { text-decoration: none; font-weight: 600; color: var(--muted); }
        .lg-foot a:hover { color: var(--brand); }

        /* Entrance */
        .lg-in { animation: lg-up .6s cubic-bezier(.2,.8,.2,1) both; }
        .lg-intro.lg-in { animation-delay: .05s; }
        .lg-feats.lg-in { animation-delay: .18s; }
        .lg-card.lg-in { animation-delay: .1s; }
        @keyframes lg-up { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }
        @keyframes lg-spin { to { transform: rotate(360deg); } }
        @keyframes lg-shake { 0%, 100% { transform: translateX(0); } 20%, 60% { transform: translateX(-5px); } 40%, 80% { transform: translateX(5px); } }

        @media (max-height: 760px) {
            .lg-page { padding: 18px 36px 12px; }
            .lg-title { font-size: clamp(26px, 3vw, 36px); margin: 8px 0 8px; }
            .lg-feats { display: none; }
            .lg-card-brand { display: none; }
        }

        /* Tablet & phone */
        @media (max-width: 1023.98px) {
            html, body { overflow: auto; }
            .lg-page { padding: 20px 20px 16px; max-width: 560px; height: auto; min-height: 100%; overflow: visible; }
            .lg-grid { grid-template-columns: minmax(0, 1fr); grid-template-areas: "copy" "card"; row-gap: 18px; padding: 16px 0 12px; align-items: start; }
            .lg-copy { gap: 16px; }
            .lg-title { font-size: clamp(28px, 7vw, 36px); margin: 10px 0 8px; }
            .lg-lead { font-size: 14px; }
            .lg-feats { max-width: none; }
            .lg-ring { width: 300px; height: 300px; border-width: 60px; right: -130px; top: -130px; }
            .lg-blob { width: 320px; height: 320px; left: -160px; bottom: -180px; }
            .lg-glow { display: none; }
            .lg-card-brand { display: none; }
        }
        @media (max-width: 1023.98px) and (min-height: 780px) {
            html, body { overflow: hidden; height: 100%; }
            .lg-page { height: 100dvh; overflow: hidden; }
        }
        @media (max-width: 520px) {
            .lg-page { padding: calc(16px + env(safe-area-inset-top)) 16px calc(12px + env(safe-area-inset-bottom)); }
            .lg-card { padding: 20px 16px 14px; border-radius: 16px; }
            .lg-card h1 { font-size: 22px; }
            .lg-input { font-size: 16px; height: 44px; }
            .lg-feats { display: none; }
            .lg-foot { justify-content: center; text-align: center; }
        }
        @media (max-width: 340px) {
            .lg-brand small { display: none; }
            .lg-help { white-space: normal; text-align: center; }
            .lg-help::before, .lg-help::after { display: none; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition: none !important; }
        }
    </style>
</head>
<body>
    <div class="lg-deco" aria-hidden="true">
        <span class="lg-ring"></span>
        <span class="lg-blob"></span>
        <span class="lg-glow"></span>
    </div>

    <div class="lg-page">
        <a class="lg-brand" href="{{ route('site.home') }}">
            <img src="{{ $site->logoUrl() }}" alt="{{ $site->siteName() }}">
            <span><b>{{ $site->siteName() }}</b><small>B2B Trading Platform</small></span>
        </a>

        <main class="lg-grid">
            <div class="lg-copy">
            <section class="lg-intro lg-in">
                <span class="lg-pill">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    Sales Department
                </span>
                <h2 class="lg-title">Welcome Back<span>Sales Team</span></h2>
                <p class="lg-lead">Log in to access your dashboard, manage your shops and track your sales activities.</p>
            </section>

            <section class="lg-feats lg-in" aria-label="What you can do">
                <div class="lg-feat">
                    <i><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M8 17v-5"/><path d="M13 17V8"/><path d="M18 17v-3"/></svg></i>
                    <b>Track Sales</b>
                    <small>View your targets and performance</small>
                </div>
                <div class="lg-feat">
                    <i><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></i>
                    <b>Manage Shops</b>
                    <small>Visit and add your assigned shops</small>
                </div>
                <div class="lg-feat">
                    <i><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg></i>
                    <b>Update Orders</b>
                    <small>Keep your orders and visits updated</small>
                </div>
            </section>
            </div>

            <div class="lg-card lg-in">
                <div class="lg-card-brand">
                    <img src="{{ $site->logoUrl() }}" alt="{{ $site->siteName() }}">
                    <span><b>{{ $site->siteName() }}</b><small>Sales Department</small></span>
                </div>

                <h1>Sign In</h1>
                <p class="lg-sub">Enter your email and password to continue.</p>

                @if ($errors->any() || session('error'))
                    <div class="lg-error" role="alert">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
                        <span>{{ $errors->first() ?: session('error') }}</span>
                    </div>
                    @include('partials.portal-hint')
                @endif

                <form id="lg-form" action="{{ url()->current() }}" method="post" novalidate>
                    @csrf
                    <label class="lg-label" for="lg-email">Email</label>
                    <div class="lg-field">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                        <input class="lg-input" id="lg-email" type="email" name="email" value="{{ old('email', request('email')) }}" placeholder="Enter your email"
                               autocomplete="username" inputmode="email" autocapitalize="off" spellcheck="false" required>
                    </div>

                    <label class="lg-label" for="lg-password">Password</label>
                    <div class="lg-field">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <input class="lg-input has-eye" id="lg-password" type="password" name="password" placeholder="Enter your password"
                               autocomplete="current-password" required>
                        <button type="button" class="lg-eye" id="lg-eye" aria-label="Show password" aria-pressed="false">
                            <svg class="on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2.06 12.35a1 1 0 0 1 0-.7 10.75 10.75 0 0 1 19.88 0 1 1 0 0 1 0 .7 10.75 10.75 0 0 1-19.88 0"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.53 13.53 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/></svg>
                        </button>
                    </div>
                    <div class="lg-caps" id="lg-caps">Caps Lock is on</div>

                    <div class="lg-row">
                        <label class="lg-remember"><input type="checkbox" name="remember" value="1" {{ old('remember', true) ? 'checked' : '' }}> Remember me</label>
                        <button type="button" class="lg-forgot" id="lg-forgot" aria-expanded="false" aria-controls="lg-note">Forgot password?</button>
                    </div>
                    <div class="lg-note" id="lg-note">Ask your sales manager or the {{ $site->siteName() }} office to reset your password from the admin panel.</div>

                    <button class="lg-submit" id="lg-submit" type="submit">
                        <span class="lg-spinner" aria-hidden="true"></span>
                        <span class="label-text">Sign In</span>
                        <svg class="arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                    </button>
                </form>

                <div class="lg-help">Need help? Contact your administrator.</div>
            </div>
        </main>

        <footer class="lg-foot">
            <span>© {{ now()->year }} {{ $site->siteName() }}</span>
            <nav>
                <a href="{{ route('portal.login') }}">Partner login</a>
                <a href="{{ route('login') }}">Admin login</a>
            </nav>
        </footer>
    </div>

    <script>
        (function () {
            var form = document.getElementById('lg-form');
            var pwd = document.getElementById('lg-password');
            var eye = document.getElementById('lg-eye');
            var caps = document.getElementById('lg-caps');
            var submit = document.getElementById('lg-submit');
            var forgot = document.getElementById('lg-forgot');
            var note = document.getElementById('lg-note');
            var label = submit.querySelector('.label-text');

            eye.addEventListener('click', function () {
                var show = pwd.type === 'password';
                pwd.type = show ? 'text' : 'password';
                eye.setAttribute('aria-pressed', show ? 'true' : 'false');
                eye.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                pwd.focus();
            });
            ['keydown', 'keyup'].forEach(function (evt) {
                pwd.addEventListener(evt, function (e) {
                    if (e.getModifierState) caps.classList.toggle('show', e.getModifierState('CapsLock'));
                });
            });
            pwd.addEventListener('blur', function () { caps.classList.remove('show'); });
            forgot.addEventListener('click', function () {
                forgot.setAttribute('aria-expanded', note.classList.toggle('show') ? 'true' : 'false');
            });
            form.addEventListener('submit', function (e) {
                if (!form.checkValidity()) {
                    e.preventDefault();
                    form.reportValidity();
                    return;
                }
                submit.classList.add('is-loading');
                label.textContent = 'Signing in…';
            });
            window.addEventListener('pageshow', function () {
                submit.classList.remove('is-loading');
                label.textContent = 'Sign In';
            });
        })();
    </script>
</body>
</html>
