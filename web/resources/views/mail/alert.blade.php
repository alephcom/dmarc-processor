<x-mail::message>
# {{ $alert->title }}

@if ($alert->tenant)
**Customer:** {{ $alert->tenant->name }}@if ($alert->domain) · **Domain:** {{ $alert->domain->name }}@endif

@endif
{{ $alert->body }}

<x-mail::button :url="$url">
View alert
</x-mail::button>

You're receiving this because this address is set up as an alert channel in {{ config('app.name') }}.
</x-mail::message>
