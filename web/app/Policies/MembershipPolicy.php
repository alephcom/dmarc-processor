<?php

namespace App\Policies;

use App\Models\Membership;
use App\Models\User;
use Filament\Facades\Filament;

/** The team list is for owners and admins; role rules live in TeamService. */
class MembershipPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->is_super_admin) {
            return true;
        }
        $tenant = Filament::getTenant();

        return $tenant !== null && $user->canManage($tenant);
    }

    public function view(User $user, Membership $m): bool
    {
        return $user->is_super_admin || $user->canManage($m->tenant);
    }

    public function create(User $user): bool
    {
        return false; // members join by invitation
    }

    public function update(User $user, Membership $m): bool
    {
        return false; // via TeamService actions
    }

    public function delete(User $user, Membership $m): bool
    {
        return false; // via TeamService actions
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
