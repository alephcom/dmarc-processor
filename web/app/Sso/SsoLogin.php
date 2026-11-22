<?php

namespace App\Sso;

use App\Audit\Audit;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Decides who an SSO identity signs in as.
 *
 * An identity is accepted for a tenant only if the MSP has configured that tenant for it:
 *  - Google:    email verified by Google, Workspace hosted domain (hd) equals the email domain,
 *               and that domain is in the tenant's SSO domains.
 *  - Microsoft: the Entra directory ID (tid, from the signature-checked ID token) is in the
 *               tenant's allowed directories, and the email (UPN) domain is in its SSO domains.
 * Email addresses alone are never trusted: Entra's "mail" attribute is not verified, and
 * consumer Google accounts can use any domain.
 *
 * Super-admins can't use SSO: they must sign in with a password and two-factor code.
 */
class SsoLogin
{
    public function resolve(SsoIdentity $id): User
    {
        $email = strtolower(trim($id->email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new SsoRejected('Your identity provider did not return a usable email address.');
        }

        $linked = User::where('sso_provider', $id->provider)->where('sso_id', $id->id)->first();
        $tenants = $this->acceptingTenants($id);

        if ($linked) {
            $this->assertNotSuperAdmin($linked);
            $this->addMemberships($linked, $tenants);

            return $linked;
        }

        if ($tenants->isEmpty()) {
            throw new SsoRejected('Single sign-on isn\'t set up for your organisation. Sign in with your password, or ask your administrator for an invitation.');
        }

        return DB::transaction(function () use ($id, $email, $tenants) {
            $user = User::where('email', $email)->first();
            if ($user) {
                $this->assertNotSuperAdmin($user);
                if ($user->sso_provider && ($user->sso_provider !== $id->provider || $user->sso_id !== $id->id)) {
                    throw new SsoRejected('This account is linked to a different sign-in. Contact your administrator.');
                }
                $user->forceFill(['sso_provider' => $id->provider, 'sso_id' => $id->id])->save();
                Audit::log('user.sso_linked', "Linked {$email} to {$id->provider} sign-in", $user, $tenants->first()->id, actorId: $user->id);
            } else {
                $user = User::create(['name' => mb_substr($id->name ?: $email, 0, 255), 'email' => $email, 'password' => Str::random(64)]);
                $user->forceFill(['sso_provider' => $id->provider, 'sso_id' => $id->id, 'email_verified_at' => now()])->save();
                Audit::log('user.sso_created', "Created {$email} on first {$id->provider} sign-in", $user, $tenants->first()->id, actorId: $user->id);
            }
            $this->addMemberships($user, $tenants);

            return $user;
        });
    }

    /** @return Collection<int, Tenant> */
    public function acceptingTenants(SsoIdentity $id): Collection
    {
        $domain = $id->emailDomain();
        if ($domain === '') {
            return collect();
        }

        return Tenant::query()->whereNotNull('sso_domains')->get()->filter(function (Tenant $t) use ($id, $domain) {
            $domains = array_map('strtolower', $t->sso_domains ?? []);
            if (! in_array($domain, $domains, true)) {
                return false;
            }

            return match ($id->provider) {
                'google' => $id->emailVerified && $id->hostedDomain !== null && strtolower($id->hostedDomain) === $domain,
                'microsoft' => $id->microsoftTenantId !== null
                    && in_array(strtolower($id->microsoftTenantId), array_map('strtolower', $t->sso_microsoft_tenant_ids ?? []), true),
                default => false,
            };
        })->values();
    }

    /** @param Collection<int, Tenant> $tenants */
    private function addMemberships(User $user, Collection $tenants): void
    {
        foreach ($tenants as $t) {
            if (! Membership::where('tenant_id', $t->id)->where('user_id', $user->id)->exists()) {
                $role = in_array($t->sso_default_role, ['admin', 'viewer'], true) ? $t->sso_default_role : 'viewer';
                Membership::create(['tenant_id' => $t->id, 'user_id' => $user->id, 'role' => $role]);
                Audit::log('membership.sso_joined', "{$user->email} joined via single sign-on as {$role}", $user, $t->id, actorId: $user->id);
            }
        }
    }

    private function assertNotSuperAdmin(User $user): void
    {
        if ($user->is_super_admin) {
            throw new SsoRejected('MSP administrators must sign in with a password and two-factor code.');
        }
    }
}
