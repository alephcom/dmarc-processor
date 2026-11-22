{{-- Panel brand: favicon mark + name. Inline styles so it doesn't depend on the panel's compiled CSS. --}}
<span style="display: inline-flex; align-items: center; gap: 0.5rem; height: 100%; font-weight: 700; font-size: 1.05rem; line-height: 1; white-space: nowrap;">
    <img src="{{ asset('favicon.svg') }}" alt="" style="height: 100%; width: auto;">
    <span>{{ $name }}</span>
</span>
