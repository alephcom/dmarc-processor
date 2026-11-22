<?php

namespace App\Audit;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;

class AuthEventSubscriber
{
    public function login(Login $e): void
    {
        // Impersonation logs its own entry; don't double-log the session switch.
        if (session()->has(Audit::IMPERSONATOR_SESSION_KEY) || session()->pull('audit.skip_login')) {
            return;
        }
        Audit::log('auth.login', "Signed in: {$e->user->email}", $e->user, actorId: $e->user->getAuthIdentifier());
    }

    public function failed(Failed $e): void
    {
        $email = mb_substr((string) ($e->credentials['email'] ?? 'unknown'), 0, 120);
        Audit::log('auth.failed', "Failed sign-in attempt for {$email}", $e->user, actorId: $e->user?->getAuthIdentifier());
    }

    public function passwordReset(PasswordReset $e): void
    {
        Audit::log('auth.password_reset', "Password reset by email link: {$e->user->email}", $e->user, actorId: $e->user->getAuthIdentifier());
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [Login::class => 'login', Failed::class => 'failed', PasswordReset::class => 'passwordReset'];
    }
}
