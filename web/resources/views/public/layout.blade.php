<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('partials.favicon')
    <title>@yield('title', config('app.name'))</title>
    <meta name="description" content="Free DMARC monitoring: see who sends email as your domain and stop spoofing.">
    <style>
        :root { color-scheme: light dark; --bg: #f9f9f7; --card: #fff; --ink: #18181b; --muted: #52525b; --line: #e4e4e7; --accent: #0369a1; --accent-soft: #e0f2fe; --danger: #b91c1c; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0d0d0d; --card: #18181b; --ink: #f4f4f5; --muted: #a1a1aa; --line: #27272a; --accent: #38bdf8; --accent-soft: #0c2a3d; --danger: #f87171; } }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--ink); font: 16px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; }
        a { color: var(--accent); }
        .wrap { max-width: 960px; margin: 0 auto; padding: 0 16px; }
        header.site { border-bottom: 1px solid var(--line); background: var(--card); }
        header.site .wrap { display: flex; align-items: center; justify-content: space-between; gap: 16px; height: 64px; }
        .brand { white-space: nowrap; display: flex; align-items: center; gap: 10px; font-weight: 600; color: var(--ink); text-decoration: none; }
        .brand img { width: 28px; height: 28px; }
        nav.site { display: flex; gap: 16px; align-items: center; font-size: 15px; }
        nav.site a { text-decoration: none; white-space: nowrap; }
        @media (max-width: 520px) { nav.site .wide { display: none; } }
        h1 { font-size: clamp(28px, 5vw, 42px); line-height: 1.15; margin: 0 0 16px; letter-spacing: -0.02em; }
        h2 { font-size: 24px; margin: 0 0 12px; letter-spacing: -0.01em; }
        h3 { font-size: 17px; margin: 0 0 6px; }
        p { margin: 0 0 16px; }
        .muted { color: var(--muted); }
        .lead { font-size: 19px; color: var(--muted); max-width: 640px; }
        section { padding: 48px 0; border-bottom: 1px solid var(--line); }
        section:last-of-type { border-bottom: 0; }
        .hero { padding: 72px 0 56px; }
        .grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(min(100%, 380px), 1fr)); }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 20px; }
        .card p:last-child { margin-bottom: 0; }
        .steps { counter-reset: step; list-style: none; padding: 0; margin: 0; display: grid; gap: 16px; }
        .steps li { counter-increment: step; position: relative; padding-left: 48px; }
        .steps li::before { content: counter(step); position: absolute; left: 0; top: 0; width: 32px; height: 32px; border-radius: 50%; background: var(--accent-soft); color: var(--accent); font-weight: 600; display: grid; place-items: center; }
        .button { display: inline-block; padding: 11px 20px; border: 0; border-radius: 8px; background: var(--accent); color: #fff; font: inherit; font-weight: 600; text-decoration: none; cursor: pointer; }
        @media (prefers-color-scheme: dark) { .button { color: #0d0d0d; } }
        .button.secondary { background: transparent; color: var(--accent); border: 1px solid var(--line); }
        .actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 24px; }
        .callout { background: var(--accent-soft); border-radius: 12px; padding: 20px 24px; }
        .callout p:last-child { margin-bottom: 0; }
        form.request { max-width: 560px; }
        label { display: block; font-weight: 500; margin: 16px 0 4px; }
        .hint { font-size: 14px; color: var(--muted); margin-top: 4px; }
        input[type=text], input[type=email], textarea { width: 100%; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; background: var(--card); color: var(--ink); font: inherit; }
        textarea { min-height: 88px; resize: vertical; }
        .check { display: flex; gap: 10px; align-items: flex-start; font-weight: 400; }
        .check input { margin-top: 5px; }
        .error { color: var(--danger); font-size: 14px; margin-top: 4px; }
        .turnstile { margin-top: 20px; min-height: 65px; }
        .hp { position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden; }
        footer.site { padding: 32px 0 48px; font-size: 14px; color: var(--muted); }
        footer.site .wrap { display: flex; flex-wrap: wrap; gap: 8px 24px; justify-content: space-between; }
    </style>
    @stack('head')
</head>
<body>
<header class="site">
    <div class="wrap">
        <a class="brand" href="{{ route('public.home') }}"><img src="{{ asset('favicon.svg') }}" alt="">{{ config('app.name') }}</a>
        <nav class="site">
            <a class="wide" href="{{ route('public.request') }}">Request an account</a>
            <a href="{{ url('/app/login') }}">Sign in</a>
        </nav>
    </div>
</header>
<main>
    @yield('content')
</main>
<footer class="site">
    <div class="wrap">
        <span>{{ config('app.name') }}</span>
        <span>Free and open source. <a href="{{ config('dmarc.public_site.repo_url') }}">Source code on GitHub</a></span>
    </div>
</footer>
</body>
</html>
