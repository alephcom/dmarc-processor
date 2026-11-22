<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light dark; --bg: #f9f9f7; --card: #fff; --ink: #18181b; --muted: #52525b; --line: #e4e4e7; --accent: #0369a1; --danger: #b91c1c; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0d0d0d; --card: #18181b; --ink: #f4f4f5; --muted: #a1a1aa; --line: #27272a; --accent: #38bdf8; --danger: #f87171; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: var(--bg); color: var(--ink); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; padding: 16px; }
        main { width: 100%; max-width: 420px; background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 32px; }
        h1 { font-size: 20px; margin: 0 0 8px; }
        p { color: var(--muted); margin: 0 0 16px; }
        .brand { font-weight: 600; text-align: center; margin-bottom: 24px; color: var(--ink); }
        label { display: block; font-weight: 500; margin: 12px 0 4px; }
        input { width: 100%; padding: 9px 11px; border: 1px solid var(--line); border-radius: 8px; background: transparent; color: var(--ink); font: inherit; }
        input:read-only { color: var(--muted); }
        button, .button { display: inline-block; width: 100%; margin-top: 20px; padding: 10px; border: 0; border-radius: 8px; background: var(--accent); color: #fff; font: inherit; font-weight: 600; text-align: center; text-decoration: none; cursor: pointer; }
        .error { color: var(--danger); font-size: 14px; margin-top: 4px; }
    </style>
</head>
<body>
<main>
    <div class="brand">{{ config('app.name') }}</div>
    @yield('content')
</main>
</body>
</html>
