<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sign in · {{ $site->siteName() }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
    @include('partials.favicon')
</head>
<body style="min-height:100vh;display:grid;place-items:center;background:linear-gradient(180deg,var(--c30) 0%,var(--c30-elevated) 52%,var(--c60) 52%)">
    <div class="card" style="width:min(440px,92vw);padding:32px">
        <x-brand-logo :size="52" show-wordmark />
        <h1 style="margin:12px 0 6px;font-size:24px;letter-spacing:-0.03em">Admin portal</h1>
        <p class="muted" style="margin:0 0 22px">Sign in with credentials issued by Super Admin. Access is role-scoped and audited.</p>

        @if ($errors->any() || session('error'))
            <div style="background:#fee2e2;color:#b91c1c;border-radius:10px;padding:10px 12px;font-size:13px;margin-bottom:14px">
                {{ $errors->first() ?: session('error') }}
            </div>
            @include('partials.portal-hint')
        @endif

        <form action="{{ url()->current() }}" method="post">
            @csrf
            <label class="label">Work email</label>
            <input class="input" style="width:100%;margin-bottom:12px" type="email" name="email" value="{{ old('email', request('email', 'admin@maksinternational.com')) }}" required autocomplete="username">
            <label class="label">Password</label>
            <input class="input" style="width:100%;margin-bottom:12px" type="password" name="password" required autocomplete="current-password">
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;margin-bottom:16px">
                <input type="checkbox" name="remember" value="1"> Remember me
            </label>
            <button class="btn btn-primary" style="width:100%;justify-content:center;height:44px" type="submit">Continue to dashboard</button>
        </form>
    </div>
</body>
</html>
