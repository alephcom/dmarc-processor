<x-mail::message>
# DMARC overview, all customers

{{ $d['period'] }}

<x-mail::table>
| Customer | Domains live | Messages | Passing | Open alerts |
|:---------|-------------:|---------:|--------:|------------:|
@foreach ($d['tenants'] as $t)
| {{ $t['name'] }} | {{ $t['live'] }} / {{ $t['domains'] }} | {{ number_format($t['messages']) }} | {{ $t['pass_rate'] === null ? '—' : $t['pass_rate'].'%' }} | {{ $t['open_alerts'] }} |
@endforeach
</x-mail::table>

@if ($d['silent']->isNotEmpty())
## Domains with no reports for 72+ hours
@foreach ($d['silent'] as $dom)
- {{ $dom->name }} ({{ $dom->tenant->name }}), last report {{ $dom->last_report_at->diffForHumans() }}
@endforeach
@endif

@if ($d['msp_alerts']->isNotEmpty())
## Platform alerts
@foreach ($d['msp_alerts'] as $a)
- {{ $a->title }}
@endforeach
@endif

<x-mail::button :url="$url">
Open MSP admin
</x-mail::button>
</x-mail::message>
