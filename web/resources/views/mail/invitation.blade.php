<x-mail::message>
# You're invited

{{ $inv->inviter?->name ?? 'Your administrator' }} has invited you to join **{{ $inv->tenant->name }}** on {{ config('app.name') }} as {{ $inv->role === 'viewer' ? 'a viewer' : 'an '.$inv->role }}.

{{ config('app.name') }} monitors who sends email using your organisation's domains and helps protect them from spoofing.

<x-mail::button :url="$url">
Accept invitation
</x-mail::button>

This link works once and expires on {{ $inv->expires_at->toFormattedDateString() }}. If you weren't expecting it, you can ignore this email.
</x-mail::message>
