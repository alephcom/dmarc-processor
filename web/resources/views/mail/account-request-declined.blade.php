<x-mail::message>
# About your account request

Hi {{ $req->name }},

Thank you for asking for a free {{ config('app.name') }} account for {{ $req->organisation }}. Unfortunately we can't host it for you.

@if ($note)
{{ $note }}
@endif

The software is free and open source, so you can run your own copy by following the instructions on GitHub.

<x-mail::button :url="$repoUrl">
Set up your own
</x-mail::button>
</x-mail::message>
