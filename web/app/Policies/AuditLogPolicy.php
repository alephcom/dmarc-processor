<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;
use Filament\Facades\Filament;

/** Read-only for owners/admins (their tenant) and super-admins (everything). Nobody edits history. */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->is_super_admin) {
            return true;
        }
        $tenant = Filament::getTenant();

        return $tenant !== null && $user->canManage($tenant);
    }

    public function view(User $user, AuditLog $log): bool
    {
        return $user->is_super_admin || ($log->tenant !== null && $user->canManage($log->tenant));
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AuditLog $log): bool
    {
        return false;
    }

    public function delete(User $user, AuditLog $log): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
