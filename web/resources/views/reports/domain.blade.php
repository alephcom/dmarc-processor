@extends('reports.layout')
@section('title', $r['domain']->name)
@section('content')
    <h1>{{ $r['domain']->name }}</h1>
    <p class="subtitle">{{ $r['tenant']->name }} · policy {{ $r['advice']->current }} · target p={{ $r['domain']->target_policy }}</p>

    <div class="stats">
        <div class="stat"><div class="k">Messages</div><div class="v">{{ number_format($r['totals']['total']) }}</div></div>
        <div class="stat"><div class="k">Passing DMARC</div><div class="v">@include('reports.partials-rate', ['rate' => $r['pass_rate']])</div></div>
        <div class="stat"><div class="k">DKIM aligned</div><div class="v">@include('reports.partials-rate', ['rate' => $r['dkim_rate']])</div></div>
        <div class="stat"><div class="k">SPF aligned</div><div class="v">@include('reports.partials-rate', ['rate' => $r['spf_rate']])</div></div>
    </div>

    <section>
        <h2>Mail sent as {{ $r['domain']->name }}</h2>
        {!! $r['chart'] !!}
    </section>

    <section>
        <h2>Recommendation</h2>
        <div class="callout {{ $r['advice']->status }}">
            <strong>{{ $r['advice']->headline }}</strong>
            {{ $r['advice']->explanation }}
        </div>
        @if ($r['advice']->checks)
            <ul class="checks">
                @foreach ($r['advice']->checks as [$ok, $text])
                    <li class="{{ $ok ? 'good' : 'bad' }}">{{ $ok ? '✓' : '✗' }} {{ $text }}</li>
                @endforeach
            </ul>
        @endif
        @if ($r['advice']->record)
            <p>Record to publish at <strong>_dmarc.{{ $r['domain']->name }}</strong> (TXT):</p>
            <code>{{ $r['advice']->record }}</code>
        @endif
    </section>

    @if ($r['alerts']->isNotEmpty())
        <section>
            <h2>Open issues</h2>
            @foreach ($r['alerts'] as $a)
                <div class="callout {{ $a->severity }}"><strong>{{ $a->title }}</strong>{{ $a->body }}</div>
            @endforeach
        </section>
    @endif

    <section>
        <h2>Who is sending as this domain</h2>
        @if ($r['sources'])
            <table>
                <thead><tr><th>Sender</th><th>Status</th><th class="n">Messages</th><th class="n">DMARC pass</th><th class="n">DKIM</th><th class="n">SPF</th></tr></thead>
                <tbody>
                @foreach ($r['sources'] as $s)
                    <tr>
                        <td>{{ $s['label'] }}</td>
                        <td><span class="pill">{{ ['legit' => 'Legitimate', 'suspicious' => 'Suspicious'][$s['classification']] ?? 'Unknown' }}</span></td>
                        <td class="n">{{ number_format($s['total']) }}</td>
                        <td class="n">@include('reports.partials-rate', ['rate' => $s['pass_rate']])</td>
                        <td class="n">{{ $s['dkim_rate'] === null ? '—' : $s['dkim_rate'].'%' }}</td>
                        <td class="n">{{ $s['spf_rate'] === null ? '—' : $s['spf_rate'].'%' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @else
            <p>No reports in this period.</p>
        @endif
    </section>

    @if ($r['reporters']->isNotEmpty())
        <section>
            <h2>Reports received from</h2>
            <table>
                <thead><tr><th>Mailbox provider</th><th class="n">Reports</th><th class="n">Messages</th></tr></thead>
                <tbody>
                @foreach ($r['reporters'] as $rep)
                    <tr><td>{{ $rep->reporter_org }}</td><td class="n">{{ number_format($rep->reports) }}</td><td class="n">{{ number_format($rep->messages) }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </section>
    @endif
@endsection
