{{-- DMARC pass rate as a small bar plus the number. Inline styles: panels use Filament's prebuilt CSS. --}}
@php
    $rate = $getState();
    $color = $rate === null ? 'gray' : ($rate >= 98 ? 'success' : ($rate >= 90 ? 'warning' : 'danger'));
@endphp
<div class="fi-ta-text" style="display: flex; align-items: center; justify-content: flex-end; gap: 0.5rem; padding: 1rem 0.75rem;">
    @if ($rate === null)
        <span style="color: var(--gray-400);">—</span>
    @else
        <span role="img" aria-label="{{ $rate }}% passing" style="display: inline-block; width: 4rem; height: 0.375rem; border-radius: 9999px; background: color-mix(in oklab, var(--gray-400) 25%, transparent); overflow: hidden;">
            <span style="display: block; height: 100%; width: {{ max(2, min(100, $rate)) }}%; border-radius: inherit; background: var(--{{ $color }}-500);"></span>
        </span>
        <span style="min-width: 3.25rem; text-align: right; font-variant-numeric: tabular-nums; font-size: 0.875rem; color: var(--{{ $color }}-600);">{{ $rate }}%</span>
    @endif
</div>
