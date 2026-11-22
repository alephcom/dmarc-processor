<x-mail::message>
# Email security summary

**{{ $d['tenant']->name }}** · {{ $d['period'] }}

@forelse ($d['domains'] as $row)
## {{ $row['name'] }}

@if ($row['messages'] === 0)
No reports in this period.
@else
**{{ number_format($row['messages']) }}** messages · **{{ $row['pass_rate'] }}%** passing DMARC{{ $row['change'] !== null ? ' ('.($row['change'] >= 0 ? '+' : '').$row['change'].' points vs previous period)' : '' }}
@endif

@if ($row['legit_failing'])
**Your senders that are failing DMARC (fix these):**

@foreach ($row['legit_failing'] as $f)
- {{ $f['label'] }}: {{ number_format($f['failing']) }} messages failing
@endforeach

@endif
@if ($row['other_failing'])
**Unrecognised or suspicious senders:**

@foreach ($row['other_failing'] as $f)
- {{ $f['label'] }}: {{ number_format($f['failing']) }} messages
@endforeach

@endif
@if ($row['new_senders'])
**New senders:** {{ implode(', ', array_slice($row['new_senders'], 0, 5)) }}{{ count($row['new_senders']) > 5 ? ' and '.(count($row['new_senders']) - 5).' more' : '' }}

@endif
**Policy:** {{ $row['advice']->current }}. {{ $row['advice']->headline }}.

@empty
No domains are set up yet.
@endforelse

@if ($d['open_alerts']->isNotEmpty())
## Open alerts

@foreach ($d['open_alerts'] as $a)
- **{{ ucfirst($a->severity) }}:** {{ $a->title }}
@endforeach

@endif
<x-mail::button :url="$url">
Open dashboard
</x-mail::button>

You receive this summary as an owner or admin of {{ $d['tenant']->name }}. Your administrator can change how often it is sent.
</x-mail::message>
