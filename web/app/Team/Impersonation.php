<?php

namespace App\Team;

use App\Audit\Audit;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Lets a super-admin sign in as a tenant user to see exactly what they see.
 * Never as another super-admin; always audited; the banner offers a way back.
 */
class Impersonation
{
    public static function active(): bool
    {
        return session()->has(Audit::IMPERSONATOR_SESSION_KEY);
    }

    public static function impersonator(): ?User
    {
        $id = session(Audit::IMPERSONATOR_SESSION_KEY);

        return $id ? User::find($id) : null;
    }

    public static function canImpersonate(User $admin, User $target): bool
    {
        return $admin->is_super_admin && ! $target->is_super_admin && $admin->id !== $target->id && ! self::active();
    }

    public function start(User $admin, User $target): void
    {
        if (! self::canImpersonate($admin, $target)) {
            throw new TeamRuleException('You can\'t sign in as this user.');
        }
        Audit::log('impersonation.started', "{$admin->email} signed in as {$target->email}", $target, $target->tenants()->value('tenants.id'));

        session()->put(Audit::IMPERSONATOR_SESSION_KEY, $admin->id);
        $this->switchTo($target);
    }

    public function stop(): ?User
    {
        $admin = self::impersonator();
        $target = Auth::user();
        if (! $admin || ! $admin->is_super_admin) {
            session()->forget(Audit::IMPERSONATOR_SESSION_KEY);

            return null;
        }
        session()->forget(Audit::IMPERSONATOR_SESSION_KEY);
        session()->put('audit.skip_login', true);
        $this->switchTo($admin);
        Audit::log('impersonation.ended', "{$admin->email} stopped signing in as {$target?->email}", $target, $target?->tenants()->value('tenants.id'));

        return $admin;
    }

    private function switchTo(User $user): void
    {
        $guard = Auth::guard('web');
        $guard->login($user);
        // Keep AuthenticateSession happy: it compares this hash with the signed-in user's password.
        session()->put('password_hash_web', $guard->hashPasswordForCookie($user->getAuthPassword()));
        session()->regenerate();
    }
}
