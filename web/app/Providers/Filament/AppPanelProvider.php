<?php

namespace App\Providers\Filament;

use App\Filament\Support\InitialsAvatarProvider;
use App\Http\Middleware\EnforceTenantMfa;
use App\Http\Middleware\RequireSuperAdminTwoFactor;
use App\Models\Tenant;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/** Tenant workspace: domains, setup wizard, dashboards, alerts. */
class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('app')
            ->path('app')
            ->default()
            ->login()
            ->profile()
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->tenant(Tenant::class, slugAttribute: 'slug')
            ->tenantMiddleware([EnforceTenantMfa::class], isPersistent: true)
            ->brandName(config('app.name'))
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('partials.favicon'))
            ->colors(['primary' => Color::Sky])
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()])
            ->discoverResources(in: app_path('Filament/App/Resources'), for: 'App\\Filament\\App\\Resources')
            ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\\Filament\\App\\Pages')
            ->discoverWidgets(in: app_path('Filament/App/Widgets'), for: 'App\\Filament\\App\\Widgets')
            ->pages([Dashboard::class])
            ->databaseNotifications()
            ->renderHook(PanelsRenderHook::BODY_START, fn () => view('filament.impersonation-banner'))
            // SSO is offered on the tenant panel only; MSP admins always use password + 2FA.
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn () => view('filament.sso-buttons'))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                // Super-admins have owner rights in every tenant: two-factor is mandatory here too.
                RequireSuperAdminTwoFactor::class,
            ]);
    }
}
