<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Session refreshed · Maks International</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { min-height: 100vh; margin: 0; display: grid; place-items: center; font-family: Inter, system-ui, sans-serif; background: #f3f4f6; color: #111827; }
        .card { width: min(420px, 92vw); background: #fff; border-radius: 16px; padding: 28px; box-shadow: 0 12px 40px rgba(15, 23, 42, .08); }
        h1 { margin: 0 0 8px; font-size: 22px; }
        p { margin: 0 0 18px; color: #6b7280; line-height: 1.5; }
        a { display: inline-flex; align-items: center; justify-content: center; height: 42px; padding: 0 16px; border-radius: 10px; background: #4f46e5; color: #fff; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
    @php
        $referer = url()->previous();
        $target = $referer && $referer !== url()->current() ? $referer : url('/');
    @endphp
    <div class="card">
        <h1>Session refreshed</h1>
        <p>Your security token expired. Continue to reload a fresh page and try again.</p>
        <a href="{{ $target }}">Continue</a>
    </div>
    <script>window.location.replace(@json($target));</script>
</body>
</html>
