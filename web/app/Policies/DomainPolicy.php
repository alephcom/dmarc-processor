<?php

namespace App\Policies;

use App\Models\Domain;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Tenant scoping of queries is done by Filament tenancy; these checks add role-based
 * permissions and a second line of defence against cross-tenant access.
 */
class DomainPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Domain $domain): bool
    {
        return $user->canAccessTenant($domain->tenant);
    }

    public function create(User $user): bool
    {
        $tenant = Filament::getTenant();

        return $tenant !== null && $user->canManage($tenant);
    }

    public function update(User $user, Domain $domain): bool
    {
        return $user->canManage($domain->tenant);
    }

    public function delete(User $user, Domain $domain): bool
    {
        return $user->canManage($domain->tenant);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
