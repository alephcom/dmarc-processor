<x-mail::message>
# New account request

**{{ $req->name }}** ({{ $req->email }}) has asked for a free account for **{{ $req->organisation }}**.

Domains:
{{ $req->domains }}

@if ($req->message)
Message:
{{ $req->message }}
@endif

Please review it within {{ config('dmarc.public_site.review_hours') }} hours.

<x-mail::button :url="$url">
Review request
</x-mail::button>
</x-mail::message>
