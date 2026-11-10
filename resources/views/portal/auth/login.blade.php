<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>B2B Partner Login · {{ $site->siteName() }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
    @include('partials.favicon')
    <style>
        .pl-body {
            position: relative;
            min-height: 100vh;
            display: grid;
            place-items: center;
            overflow: hidden;
            background: linear-gradient(180deg, var(--c30) 0%, var(--c30-elevated) 52%, var(--c60) 52%);
        }
        .pl-bg {
            position: absolute;
            inset: 0 0 48% 0;
            overflow: hidden;
            pointer-events: none;
        }
        .pl-orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(60px);
            opacity: .55;
            animation: pl-drift 14s ease-in-out infinite alternate;
        }
        .pl-orb-1 { width: 420px; height: 420px; left: -80px; top: -140px; background: #7C3AED; }
        .pl-orb-2 { width: 360px; height: 360px; right: -60px; top: -60px; background: #4F46E5; animation-duration: 18s; animation-delay: -4s; }
        .pl-orb-3 { width: 260px; height: 260px; left: 45%; top: 40%; background: #C026D3; opacity: .3; animation-duration: 22s; animation-delay: -8s; }
        .pl-grid {
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(255,255,255,.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,.04) 1px, transparent 1px);
            background-size: 44px 44px;
            mask-image: linear-gradient(180deg, #000 30%, transparent 100%);
        }

        .pl-card {
            position: relative;
            z-index: 1;
            width: min(440px, 92vw);
            padding: 32px;
            box-shadow: 0 24px 60px rgba(15, 14, 20, .22);
            animation: pl-rise .7s cubic-bezier(.2, .8, .2, 1) both;
        }
        .pl-logo { display: inline-flex; animation: pl-pop .6s cubic-bezier(.2, .8, .2, 1) .15s both; }
        .pl-logo img { animation: pl-glow 3.5s ease-in-out 1s infinite; }
        .pl-step { animation: pl-fade-up .55s cubic-bezier(.2, .8, .2, 1) both; }
        .pl-step:nth-child(1) { animation-delay: .25s; }
        .pl-step:nth-child(2) { animation-delay: .32s; }
        .pl-step:nth-child(3) { animation-delay: .39s; }
        .pl-step:nth-child(4) { animation-delay: .46s; }
        .pl-step:nth-child(5) { animation-delay: .53s; }
        .pl-step:nth-child(6) { animation-delay: .60s; }

        .pl-card .input { transition: border-color .2s ease, box-shadow .2s ease; }
        .pl-card .input:focus { border-color: var(--brand); box-shadow: 0 0 0 4px rgba(109, 40, 217, .14); }

        .pl-submit {
            position: relative;
            overflow: hidden;
            width: 100%;
            justify-content: center;
            height: 44px;
            transition: transform .2s ease, box-shadow .2s ease;
        }
        .pl-submit:hover { transform: translateY(-1px); box-shadow: 0 10px 24px rgba(109, 40, 217, .32); }
        .pl-submit:active { transform: translateY(0); }
        .pl-submit::after {
            content: "";
            position: absolute;
            top: 0;
            left: -60%;
            width: 40%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,.28), transparent);
            transform: skewX(-20deg);
            animation: pl-shine 4s ease-in-out 1.4s infinite;
        }
        .pl-submit.is-loading { pointer-events: none; opacity: .9; }
        .pl-spinner {
            display: none;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,.4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: pl-spin .7s linear infinite;
        }
        .pl-submit.is-loading .pl-spinner { display: inline-block; }

        .pl-error { animation: pl-shake .45s ease both; }

        @keyframes pl-rise { from { opacity: 0; transform: translateY(24px) scale(.98); } to { opacity: 1; transform: none; } }
        @keyframes pl-fade-up { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
        @keyframes pl-pop { from { opacity: 0; transform: scale(.85); } to { opacity: 1; transform: none; } }
        @keyframes pl-glow {
            0%, 100% { filter: drop-shadow(0 6px 14px rgba(124, 58, 237, .35)); }
            50% { filter: drop-shadow(0 8px 22px rgba(124, 58, 237, .65)); }
        }
        @keyframes pl-drift {
            from { transform: translate(0, 0) scale(1); }
            to { transform: translate(60px, 40px) scale(1.12); }
        }
        @keyframes pl-shine { 0%, 70% { left: -60%; } 100% { left: 130%; } }
        @keyframes pl-spin { to { transform: rotate(360deg); } }
        @keyframes pl-shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-6px); }
            40%, 80% { transform: translateX(6px); }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition: none !important; }
        }
    </style>
</head>
<body class="pl-body">
    <div class="pl-bg" aria-hidden="true">
        <span class="pl-orb pl-orb-1"></span>
        <span class="pl-orb pl-orb-2"></span>
        <span class="pl-orb pl-orb-3"></span>
        <span class="pl-grid"></span>
    </div>

    <div class="card pl-card">
        <div class="pl-logo"><x-brand-logo :size="52" show-wordmark /></div>
        <div>
            <h1 class="pl-step" style="margin:12px 0 6px;font-size:24px;letter-spacing:-0.03em">B2B Partner Login</h1>
            <p class="muted pl-step" style="margin:0 0 22px">Approved shop owners only. Wholesale prices and stock are private to your account.</p>
            @if ($errors->any() || session('error'))
                <div class="pl-error" style="background:#fee2e2;color:#b91c1c;border-radius:10px;padding:10px 12px;font-size:13px;margin-bottom:14px">{{ $errors->first() ?: session('error') }}</div>
                @include('partials.portal-hint')
            @endif
            <form class="pl-step" action="{{ url()->current() }}" method="post" onsubmit="this.querySelector('.pl-submit').classList.add('is-loading')">
                @csrf
                <label class="label">Work email</label>
                <input class="input" style="width:100%;margin-bottom:12px" type="email" name="email" value="{{ old('email', request('email')) }}" required>
                <label class="label">Password</label>
                <input class="input" style="width:100%;margin-bottom:16px" type="password" name="password" required>
                <button class="btn btn-primary pl-submit" type="submit">
                    <span class="pl-spinner" aria-hidden="true"></span>
                    Enter shop portal
                </button>
            </form>
            <p class="muted pl-step" style="margin-top:16px;text-align:center"><a href="{{ route('login') }}">Admin login</a></p>
        </div>
    </div>
</body>
</html>
