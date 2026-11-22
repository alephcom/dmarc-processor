@extends('reports.layout')
@section('title', $r['tenant']->name)
@section('content')
    <h1>{{ $r['tenant']->name }}</h1>
    <p class="subtitle">{{ count($r['domains']) }} {{ \Illuminate\Support\Str::plural('domain', count($r['domains'])) }} monitored</p>

    <div class="stats">
        <div class="stat"><div class="k">Messages</div><div class="v">{{ number_format($r['totals']['total']) }}</div></div>
        <div class="stat"><div class="k">Passing DMARC</div><div class="v">@include('reports.partials-rate', ['rate' => $r['pass_rate']])</div></div>
        <div class="stat"><div class="k">Records live</div><div class="v">{{ collect($r['domains'])->where('live', true)->count() }} / {{ count($r['domains']) }}</div></div>
        <div class="stat"><div class="k">Open issues</div><div class="v {{ $r['alerts']->isNotEmpty() ? 'warn' : 'good' }}">{{ $r['alerts']->count() }}</div></div>
    </div>

    <section>
        <h2>Mail sent as your domains</h2>
        {!! $r['chart'] !!}
    </section>

    <section>
        <h2>Domains</h2>
        <table>
            <thead><tr><th>Domain</th><th>Policy</th><th class="n">Messages</th><th class="n">DMARC pass</th><th class="n">To review</th><th>Next step</th></tr></thead>
            <tbody>
            @foreach ($r['domains'] as $d)
                <tr>
                    <td>{{ $d['name'] }}@unless ($d['live']) <span class="pill">not live</span>@endunless</td>
                    <td>{{ $d['policy'] }}</td>
                    <td class="n">{{ number_format($d['messages']) }}</td>
                    <td class="n">@include('reports.partials-rate', ['rate' => $d['pass_rate']])</td>
                    <td class="n {{ $d['review'] ? 'warn' : '' }}">{{ $d['review'] }}</td>
                    <td>{{ $d['next'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>

    @if ($r['alerts']->isNotEmpty())
        <section>
            <h2>Open issues</h2>
            @foreach ($r['alerts'] as $a)
                <div class="callout {{ $a->severity }}"><strong>{{ $a->title }}</strong>{{ $a->body }}</div>
            @endforeach
        </section>
    @endif
@endsection
