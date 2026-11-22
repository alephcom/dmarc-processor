<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Super-admins act as owner of every tenant, so the tenant panel enforces the same two-factor
 * requirement as /admin. Filament's own "required" switch is evaluated once when routes are
 * registered (before a user is known), so it can't express "required for super-admins only".
 */
class RequireSuperAdminTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user?->is_super_admin && blank($user->getAppAuthenticationSecret())) {
            return redirect(Filament::getPanel('admin')->route('auth.multi-factor-authentication.set-up-required'));
        }

        return $next($request);
    }
}
