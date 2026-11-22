<?php

namespace App\Http\Controllers;

use App\Sso\SsoIdentity;
use App\Sso\SsoLogin;
use App\Sso\SsoRejected;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class SsoController
{
    public const PROVIDERS = ['google' => 'Google', 'microsoft' => 'Microsoft'];

    public static function enabled(string $provider): bool
    {
        return array_key_exists($provider, self::PROVIDERS) && filled(config("services.{$provider}.client_id"));
    }

    public function redirect(string $provider): RedirectResponse
    {
        abort_unless(self::enabled($provider), 404);

        return Socialite::driver($provider)->scopes(['openid', 'email', 'profile'])->redirect();
    }

    public function callback(string $provider, SsoLogin $sso): RedirectResponse
    {
        abort_unless(self::enabled($provider), 404);

        try {
            $identity = $this->identity($provider);
            $user = $sso->resolve($identity);
        } catch (SsoRejected $e) {
            return redirect('/app/login')->with('sso_error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return redirect('/app/login')->with('sso_error', 'Sign-in with '.self::PROVIDERS[$provider].' failed. Please try again.');
        }

        Auth::login($user);
        session()->regenerate();
        // The identity provider handled the second factor; see EnforceTenantMfa.
        session()->put('auth_via_sso', $provider);

        return redirect()->intended('/app');
    }

    /** Map the provider's validated response to the fields we trust. */
    private function identity(string $provider): SsoIdentity
    {
        $driver = Socialite::driver($provider);
        $u = $driver->user();
        $raw = $u->getRaw();

        return match ($provider) {
            'google' => new SsoIdentity('google', (string) $u->getId(), (string) $u->getEmail(), (string) $u->getName(),
                emailVerified: filter_var($raw['email_verified'] ?? false, FILTER_VALIDATE_BOOL),
                hostedDomain: $raw['hd'] ?? null),
            'microsoft' => new SsoIdentity('microsoft', (string) $u->getId(), (string) ($raw['userPrincipalName'] ?? $u->getEmail()), (string) $u->getName(),
                // tid from the ID token, which the provider validated (signature, issuer, audience, expiry).
                microsoftTenantId: method_exists($driver, 'getClaims') ? ($driver->getClaims()?->tid ?? null) : null),
        };
    }
}
