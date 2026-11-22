@php
    // Tenant panel only: MSP admins must use password + two-factor.
    $providers = filament()->getCurrentPanel()?->getId() !== 'app' ? [] : array_filter(\App\Http\Controllers\SsoController::PROVIDERS, fn ($label, $key) => \App\Http\Controllers\SsoController::enabled($key), ARRAY_FILTER_USE_BOTH);
@endphp
@if (session('sso_error'))
    <div role="alert" style="margin-top:1rem;padding:.75rem 1rem;border-radius:.5rem;background:#fef2f2;color:#991b1b;font-size:.875rem;">{{ session('sso_error') }}</div>
@endif
@if ($providers)
    <div style="display:flex;flex-direction:column;gap:.5rem;margin-top:1rem;">
        <div style="text-align:center;font-size:.875rem;opacity:.7;">or</div>
        @foreach ($providers as $key => $label)
            <x-filament::button tag="a" color="gray" outlined :href="route('sso.redirect', $key)">
                Sign in with {{ $label }}
            </x-filament::button>
        @endforeach
    </div>
@endif
