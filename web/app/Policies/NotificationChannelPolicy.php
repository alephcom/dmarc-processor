<?php

namespace App\Policies;

use App\Models\NotificationChannel;
use App\Models\User;
use Filament\Facades\Filament;

/** Channels hold credentials (webhook secrets) and recipients: owners/admins only. */
class NotificationChannelPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->is_super_admin) {
            return true;
        }
        $tenant = Filament::getTenant();

        return $tenant !== null && $user->canManage($tenant);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, NotificationChannel $channel): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        return $channel->tenant !== null && $user->canManage($channel->tenant);
    }

    public function update(User $user, NotificationChannel $channel): bool
    {
        return $this->view($user, $channel);
    }

    public function delete(User $user, NotificationChannel $channel): bool
    {
        return $this->view($user, $channel);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
