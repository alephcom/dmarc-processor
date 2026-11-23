@php
    // Tenant panel only: MSP admins must use password + two-factor.
    $providers = filament()->getCurrentPanel()?->getId() !== 'app' ? [] : array_filter(\App\Http\Controllers\SsoController::PROVIDERS, fn ($label, $key) => \App\Http\Controllers\SsoController::enabled($key), ARRAY_FILTER_USE_BOTH);
    $icons = ['microsoft' => 'heroicon-m-building-office-2', 'google' => 'heroicon-m-envelope'];
@endphp
@if (session('sso_error'))
    <x-filament::callout color="danger" icon="heroicon-o-exclamation-triangle" role="alert" style="margin-top: 1rem;" :description="session('sso_error')" />
@endif
@if ($providers)
    <div style="display: flex; flex-direction: column; gap: 0.5rem; margin-top: 1.25rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem; font-size: 0.75rem; color: var(--gray-500); text-transform: uppercase; letter-spacing: 0.05em;">
            <span style="flex: 1; height: 1px; background: currentColor; opacity: 0.3;"></span>
            or continue with
            <span style="flex: 1; height: 1px; background: currentColor; opacity: 0.3;"></span>
        </div>
        @foreach ($providers as $key => $label)
            <x-filament::button tag="a" color="gray" outlined :icon="$icons[$key] ?? null" :href="route('sso.redirect', $key)">
                Sign in with {{ $label }}
            </x-filament::button>
        @endforeach
    </div>
@endif
