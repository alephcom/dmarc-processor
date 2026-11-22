<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenants can require 2FA for their users. Users without it are sent to their profile to set it up.
 */
class EnforceTenantMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();
        $user = $request->user();
        if ($tenant?->require_mfa && $user && ! $user->getAppAuthenticationSecret()) {
            $profile = Filament::getProfileUrl();
            if ($profile && ! $request->is(ltrim(parse_url($profile, PHP_URL_PATH), '/'))) {
                return redirect($profile)->with('status', 'Your organisation requires two-factor authentication.');
            }
        }

        return $next($request);
    }
}
