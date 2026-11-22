<?php

namespace App\Policies;

use App\Models\Invitation;
use App\Models\User;

class InvitationPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // only listed in tenant-scoped widgets for managers
    }

    public function view(User $user, Invitation $inv): bool
    {
        return $user->is_super_admin || $user->canManage($inv->tenant);
    }

    public function delete(User $user, Invitation $inv): bool
    {
        return $this->view($user, $inv);
    }
}
