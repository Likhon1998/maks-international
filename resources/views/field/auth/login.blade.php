<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#F4F6F9">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('field.login_title') }} · {{ $site->siteName() }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    @include('partials.favicon')
    <style>
        :root {
            --bg: #F4F6F9;
            --card: #FFFFFF;
            --ink: #0B1220;
            --text: #334155;
            --muted: #64748B;
            --line: #E2E8F0;
            --soft: #F8FAFC;
            --accent: #B91C1C;
            --safe-t: env(safe-area-inset-top, 0px);
            --safe-b: env(safe-area-inset-bottom, 0px);
        }
        *, *::before, *::after { box-sizing: border-box; }
        html, body { margin: 0; min-height: 100%; }
        body {
            min-height: 100vh;
            min-height: 100dvh;
            font-family: {{ app()->getLocale() === 'bn' ? '"Hind Siliguri", ' : '' }}"Plus Jakarta Sans", ui-sans-serif, system-ui, sans-serif;
            color: var(--text);
            background:
                radial-gradient(120% 70% at 50% -20%, #DCE7F5 0%, transparent 55%),
                radial-gradient(80% 50% at 100% 100%, rgba(185, 28, 28, 0.08) 0%, transparent 50%),
                var(--bg);
            -webkit-font-smoothing: antialiased;
        }
        a { color: inherit; text-decoration: none; }
        button, input { font: inherit; }
        img { display: block; max-width: 100%; }

        .fl {
            width: min(420px, 100%);
            min-height: 100vh;
            min-height: 100dvh;
            margin: 0 auto;
            padding: calc(28px + var(--safe-t)) 20px calc(20px + var(--safe-b));
            display: flex;
            flex-direction: column;
        }

        .fl-top {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 12px;
            animation: fl-in .45s ease both;
        }
        .fl-logo {
            width: 88px;
            height: 88px;
            border-radius: 50%;
            background: #fff;
            padding: 8px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.12);
            border: 1px solid rgba(15, 23, 42, 0.06);
        }
        .fl-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 50%;
        }
        .fl-top h1 {
            margin: 0;
            font-size: clamp(1.35rem, 5vw, 1.6rem);
            font-weight: 800;
            letter-spacing: -0.03em;
            color: var(--ink);
            line-height: 1.15;
        }
        .fl-top p {
            margin: 0;
            max-width: 28ch;
            font-size: 0.92rem;
            line-height: 1.45;
            color: var(--muted);
            font-weight: 500;
        }
        .fl-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 999px;
            background: #fff;
            border: 1px solid var(--line);
            color: var(--ink);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .fl-badge span {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--accent);
        }

        .fl-card {
            margin-top: 22px;
            background: var(--card);
            border: 1px solid rgba(15, 23, 42, 0.06);
            border-radius: 24px;
            padding: 22px 20px 18px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
            animation: fl-in .45s ease .06s both;
        }
        .fl-card h2 {
            margin: 0 0 4px;
            font-size: 1.25rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            color: var(--ink);
        }
        .fl-card .lead {
            margin: 0 0 18px;
            font-size: 0.88rem;
            color: var(--muted);
            font-weight: 500;
        }

        .fl-error {
            margin-bottom: 14px;
            padding: 12px 14px;
            border-radius: 14px;
            background: #FEF2F2;
            border: 1px solid #FECACA;
            color: #B91C1C;
            font-size: 13px;
            font-weight: 600;
            line-height: 1.4;
        }
        .fl-hint {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            margin: 0 0 14px;
            padding: 10px 14px;
            border-radius: 14px;
            background: var(--ink);
            color: #fff;
            font-weight: 700;
            font-size: 14px;
        }

        .fl-label {
            display: block;
            margin: 0 0 7px;
            font-size: 12.5px;
            font-weight: 700;
            color: var(--ink);
        }
        .fl-field { position: relative; margin-bottom: 14px; }
        .fl-field > svg {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            width: 18px;
            height: 18px;
            color: #94A3B8;
            pointer-events: none;
        }
        .fl-input {
            width: 100%;
            height: 52px;
            padding: 0 14px 0 46px;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: var(--soft);
            color: var(--ink);
            font-size: 16px;
            font-weight: 600;
            outline: none;
            transition: border-color .15s, box-shadow .15s, background .15s;
            -webkit-appearance: none;
        }
        .fl-input::placeholder { color: #94A3B8; font-weight: 500; }
        .fl-input:focus {
            background: #fff;
            border-color: #94A3B8;
            box-shadow: 0 0 0 4px rgba(11, 18, 32, 0.08);
        }
        .fl-field:focus-within > svg { color: var(--ink); }
        .fl-input.has-eye { padding-right: 52px; }
        .fl-eye {
            position: absolute;
            right: 6px;
            top: 50%;
            transform: translateY(-50%);
            width: 40px;
            height: 40px;
            border: 0;
            border-radius: 12px;
            background: transparent;
            color: #64748B;
            display: grid;
            place-items: center;
            cursor: pointer;
            -webkit-tap-highlight-color: transparent;
        }
        .fl-eye:active { background: #F1F5F9; }
        .fl-eye svg { width: 18px; height: 18px; }
        .fl-eye .off, .fl-eye[aria-pressed="true"] .on { display: none; }
        .fl-eye[aria-pressed="true"] .off { display: block; }

        .fl-caps {
            display: none;
            margin: -6px 0 12px;
            font-size: 12px;
            font-weight: 700;
            color: #B45309;
        }
        .fl-caps.show { display: block; }

        .fl-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin: 0 0 16px;
            font-size: 13px;
        }
        .fl-remember {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            color: var(--text);
            cursor: pointer;
            user-select: none;
        }
        .fl-remember input {
            width: 18px;
            height: 18px;
            margin: 0;
            accent-color: var(--ink);
        }
        .fl-forgot {
            border: 0;
            background: none;
            padding: 4px 0;
            font: inherit;
            font-weight: 700;
            color: var(--ink);
            cursor: pointer;
        }
        .fl-note {
            display: none;
            margin: -4px 0 14px;
            padding: 12px 14px;
            border-radius: 14px;
            background: #F1F5F9;
            color: #334155;
            font-size: 12.5px;
            line-height: 1.45;
            font-weight: 500;
        }
        .fl-note.show { display: block; }

        .fl-submit {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            height: 52px;
            border: 0;
            border-radius: 14px;
            background: var(--ink);
            color: #fff;
            font-size: 15.5px;
            font-weight: 800;
            letter-spacing: -0.01em;
            cursor: pointer;
            box-shadow: 0 12px 24px rgba(11, 18, 32, 0.22);
            -webkit-tap-highlight-color: transparent;
        }
        .fl-submit:active { transform: scale(0.985); }
        .fl-submit.is-loading { pointer-events: none; opacity: 0.9; }
        .fl-submit .arrow { width: 18px; height: 18px; }
        .fl-submit.is-loading .arrow { display: none; }
        .fl-spinner {
            display: none;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,.35);
            border-top-color: #fff;
            border-radius: 50%;
            animation: fl-spin .7s linear infinite;
        }
        .fl-submit.is-loading .fl-spinner { display: inline-block; }

        .fl-help {
            margin: 16px 0 0;
            text-align: center;
            font-size: 12.5px;
            color: var(--muted);
            font-weight: 500;
        }

        .fl-foot {
            margin-top: auto;
            padding-top: 24px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            text-align: center;
            color: var(--muted);
            font-size: 12px;
            font-weight: 500;
            animation: fl-in .45s ease .12s both;
        }
        .fl-foot nav {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            justify-content: center;
        }
        .fl-foot a {
            font-weight: 700;
            color: var(--ink);
        }

        @keyframes fl-in {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: none; }
        }
        @keyframes fl-spin { to { transform: rotate(360deg); } }

        @media (min-width: 720px) {
            body {
                display: grid;
                place-items: center;
                padding: 24px;
            }
            .fl {
                min-height: auto;
                padding: 0;
            }
            .fl-card { margin-top: 20px; padding: 26px 24px 20px; }
            .fl-foot { padding-top: 18px; }
        }

        @media (max-height: 720px) {
            .fl { padding-top: calc(16px + var(--safe-t)); }
            .fl-logo { width: 72px; height: 72px; }
            .fl-top p { display: none; }
            .fl-card { margin-top: 14px; padding: 18px 16px 14px; }
            .fl-input, .fl-submit { height: 48px; }
        }

        .fl-lang-bar {
            display: flex; justify-content: flex-end; margin-bottom: 8px;
        }
        .fl-lang-bar .f-lang-btn {
            display: inline-flex; align-items: center; gap: 4px;
            min-height: 34px; padding: 0 12px; border-radius: 99px;
            border: 1px solid var(--line); background: #fff; color: var(--ink);
            font-size: 12px; font-weight: 800; cursor: pointer; font-family: inherit;
        }
        .fl-lang-bar .f-lang-cur { opacity: .55; }
        .fl-lang-bar .f-lang-sep { opacity: .35; }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition: none !important; }
        }
    </style>
</head>
<body>
    <div class="fl">
        <div class="fl-lang-bar">
            @include('field.partials.locale-switch')
        </div>
        <header class="fl-top">
            <div class="fl-badge"><span aria-hidden="true"></span> {{ __('field.login_title') }}</div>
            <div class="fl-logo">
                <img src="{{ $site->logoUrl() }}" alt="{{ $site->siteName() }}">
            </div>
            <div>
                <h1>{{ $site->siteName() }}</h1>
                <p>{{ __('field.sign_in_to_continue') }}</p>
            </div>
        </header>

        <main class="fl-card">
            <h2>{{ __('field.welcome_back') }}</h2>
            <p class="lead">{{ __('field.sign_in_to_continue') }}</p>

            @if ($errors->any() || session('error'))
                <div class="fl-error" role="alert">{{ $errors->first() ?: session('error') }}</div>
                @if (session('portal_hint'))
                    <a class="fl-hint" href="{{ session('portal_hint')['url'] }}">
                        Go to {{ session('portal_hint')['label'] }} →
                    </a>
                @endif
            @endif

            <form id="fl-form" action="{{ url()->current() }}" method="post" novalidate>
                @csrf
                <label class="fl-label" for="fl-email">{{ __('field.email_or_phone') }}</label>
                <div class="fl-field">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                    <input
                        class="fl-input"
                        id="fl-email"
                        type="email"
                        name="email"
                        value="{{ old('email', request('email')) }}"
                        placeholder="you@company.com"
                        autocomplete="username"
                        inputmode="email"
                        autocapitalize="off"
                        spellcheck="false"
                        required
                    >
                </div>

                <label class="fl-label" for="fl-password">{{ __('field.password_label') }}</label>
                <div class="fl-field">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <input
                        class="fl-input has-eye"
                        id="fl-password"
                        type="password"
                        name="password"
                        placeholder="Your password"
                        autocomplete="current-password"
                        required
                    >
                    <button type="button" class="fl-eye" id="fl-eye" aria-label="Show password" aria-pressed="false">
                        <svg class="on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2.06 12.35a1 1 0 0 1 0-.7 10.75 10.75 0 0 1 19.88 0 1 1 0 0 1 0 .7 10.75 10.75 0 0 1-19.88 0"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.53 13.53 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/></svg>
                    </button>
                </div>
                <div class="fl-caps" id="fl-caps">{{ __('field.caps_on') }}</div>

                <div class="fl-row">
                    <label class="fl-remember">
                        <input type="checkbox" name="remember" value="1" {{ old('remember', true) ? 'checked' : '' }}>
                        {{ __('field.remember_me') }}
                    </label>
                    <button type="button" class="fl-forgot" id="fl-forgot" aria-expanded="false" aria-controls="fl-note">{{ __('field.forgot_password') }}</button>
                </div>
                <div class="fl-note" id="fl-note">{{ __('field.ask_office_password') }}</div>

                <button class="fl-submit" id="fl-submit" type="submit">
                    <span class="fl-spinner" aria-hidden="true"></span>
                    <span class="label-text">{{ __('field.sign_in') }}</span>
                    <svg class="arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                </button>
            </form>

            <p class="fl-help">{{ __('field.need_help') }}</p>
        </main>

        <footer class="fl-foot">
            <span>© {{ now()->year }} {{ $site->siteName() }}</span>
            <nav>
                <a href="{{ route('portal.login') }}">Partner login</a>
                <a href="{{ route('login') }}">Admin login</a>
            </nav>
        </footer>
    </div>

    <script>
        (function () {
            var form = document.getElementById('fl-form');
            var pwd = document.getElementById('fl-password');
            var eye = document.getElementById('fl-eye');
            var caps = document.getElementById('fl-caps');
            var submit = document.getElementById('fl-submit');
            var forgot = document.getElementById('fl-forgot');
            var note = document.getElementById('fl-note');
            var label = submit.querySelector('.label-text');

            eye.addEventListener('click', function () {
                var show = pwd.type === 'password';
                pwd.type = show ? 'text' : 'password';
                eye.setAttribute('aria-pressed', show ? 'true' : 'false');
                eye.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
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
                label.textContent = 'Sign in';
            });
        })();
    </script>
</body>
</html>
