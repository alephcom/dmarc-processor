@if ($rate === null)<span class="muted">—</span>@else<span class="{{ $rate >= 98 ? 'good' : ($rate >= 90 ? 'warn' : 'bad') }}">{{ $rate }}%</span>@endif
