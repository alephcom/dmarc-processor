{{-- Runs the first DNS scan after the page has rendered, so the page never blocks on DNS. --}}
<div wire:init="initialScan">
    <x-filament::loading-indicator class="h-5 w-5" />
    <span>Checking DNS records for {{ $domain }}…</span>
</div>
