<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · {{ config('dmarc.brand_name') }}</title>
    <style>
        /* Reports are documents: always light, sized for A4/Letter. */
        :root { color-scheme: light; --ink: #0b0b0b; --ink2: #52514e; --muted: #898781; --line: #e1e0d9; --surface: #fcfcfb; --good: #006300; --warn: #8a5a00; --bad: #b42318; }
        * { box-sizing: border-box; }
        html { background: #f1f0ec; }
        body { margin: 0; color: var(--ink); font: 13px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
        .page { max-width: 820px; margin: 24px auto; background: #fff; padding: 40px 48px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .toolbar { max-width: 820px; margin: 16px auto 0; display: flex; gap: 8px; justify-content: flex-end; padding: 0 16px; }
        .toolbar a, .toolbar button { font: inherit; font-weight: 600; padding: 7px 14px; border-radius: 8px; border: 1px solid var(--line); background: #fff; color: var(--ink); cursor: pointer; text-decoration: none; }
        .toolbar button.primary { background: #0b0b0b; color: #fff; border-color: #0b0b0b; }
        header.brand { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; border-bottom: 2px solid var(--ink); padding-bottom: 12px; margin-bottom: 20px; }
        header.brand img { max-height: 40px; max-width: 200px; }
        .brand-name { font-weight: 700; font-size: 15px; }
        .meta { color: var(--ink2); text-align: right; font-size: 12px; }
        h1 { font-size: 22px; margin: 0 0 2px; }
        h2 { font-size: 15px; margin: 28px 0 10px; padding-bottom: 4px; border-bottom: 1px solid var(--line); }
        .subtitle { color: var(--ink2); margin: 0 0 16px; }
        .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin: 16px 0; }
        .stat { border: 1px solid var(--line); border-radius: 8px; padding: 10px 12px; }
        .stat .k { color: var(--ink2); font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
        .stat .v { font-size: 22px; font-weight: 600; font-variant-numeric: tabular-nums; }
        table { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
        th { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--ink2); font-weight: 600; }
        td.n, th.n { text-align: right; }
        .muted { color: var(--muted); }
        .good { color: var(--good); } .warn { color: var(--warn); } .bad { color: var(--bad); }
        .pill { display: inline-block; padding: 0 6px; border-radius: 4px; border: 1px solid var(--line); font-size: 11px; color: var(--ink2); }
        .callout { border: 1px solid var(--line); border-left: 4px solid var(--ink2); border-radius: 6px; padding: 10px 12px; margin: 10px 0; }
        .callout.ready, .callout.done { border-left-color: var(--good); } .callout.blocked { border-left-color: var(--warn); } .callout.critical { border-left-color: var(--bad); }
        .callout strong { display: block; }
        ul.checks { list-style: none; padding: 0; margin: 8px 0; } ul.checks li { padding: 2px 0; }
        code { font: 12px ui-monospace, SFMono-Regular, Menlo, monospace; background: #f6f6f3; padding: 6px 8px; border-radius: 4px; display: block; word-break: break-all; }
        section { break-inside: avoid; }
        footer { margin-top: 32px; padding-top: 10px; border-top: 1px solid var(--line); color: var(--muted); font-size: 11px; display: flex; justify-content: space-between; gap: 12px; }
        @media (max-width: 640px) { .page { padding: 20px 16px; margin: 0; } .stats { grid-template-columns: repeat(2, 1fr); } }
        @page { size: A4; margin: 14mm 12mm; }
        @media print {
            html { background: #fff; }
            .toolbar { display: none; }
            .page { box-shadow: none; margin: 0; padding: 0; max-width: none; }
            h2 { break-after: avoid; }
            tr { break-inside: avoid; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    @unless ($shared)<a href="javascript:history.back()">Back</a>@endunless
    <button type="button" class="primary" onclick="window.print()">Print / Save as PDF</button>
</div>
<div class="page">
    <header class="brand">
        <div>
            @if (config('dmarc.brand_logo_url'))
                <img src="{{ config('dmarc.brand_logo_url') }}" alt="{{ config('dmarc.brand_name') }}">
            @else
                <div class="brand-name">{{ config('dmarc.brand_name') }}</div>
            @endif
        </div>
        <div class="meta">Email security report<br>{{ $r['period'] }}</div>
    </header>
    @yield('content')
    <footer>
        <span>Generated {{ now()->format('j M Y H:i') }} UTC by {{ config('dmarc.brand_name') }}{{ config('dmarc.brand_contact') ? ' · '.config('dmarc.brand_contact') : '' }}</span>
        <span>Based on DMARC aggregate reports from receiving mail servers</span>
    </footer>
</div>
</body>
</html>
