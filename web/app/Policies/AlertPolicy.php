<?php

namespace App\Policies;

use App\Models\Alert;
use App\Models\User;

class AlertPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // lists are scoped by Filament tenancy (tenant panel) or panel access (admin panel)
    }

    public function view(User $user, Alert $alert): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        return $alert->tenant !== null && $user->canAccessTenant($alert->tenant);
    }

    /** "Mark resolved". */
    public function update(User $user, Alert $alert): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        return $alert->tenant !== null && $user->canManage($alert->tenant);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, Alert $alert): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
