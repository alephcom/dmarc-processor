<?php

namespace App\Team;

use App\Audit\Audit;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * All changes to who belongs to a tenant go through here, so the role rules are enforced in one place:
 *  - owners manage everyone; admins manage admins and viewers but can't touch or create owners
 *  - nobody changes their own role or removes themselves (prevents accidental lock-out)
 *  - a tenant always keeps at least one owner
 */
class TeamService
{
    public const ROLES = [User::ROLE_OWNER => 'Owner', User::ROLE_ADMIN => 'Admin', User::ROLE_VIEWER => 'Viewer'];

    /** @return array<string, string> roles $actor may hand out in $tenant */
    public function assignableRoles(User $actor, Tenant $tenant): array
    {
        return match ($actor->roleIn($tenant)) {
            User::ROLE_OWNER => self::ROLES,
            User::ROLE_ADMIN => array_diff_key(self::ROLES, [User::ROLE_OWNER => 1]),
            default => [],
        };
    }

    public function invite(Tenant $tenant, string $email, string $role, User $by): Invitation
    {
        $email = strtolower(trim($email));
        $this->assertCanGrant($by, $tenant, $role);
        if ($tenant->users()->where('email', $email)->exists()) {
            throw new TeamRuleException("{$email} is already a member.");
        }
        [$invitation, $token] = Invitation::issue($tenant, $email, $role, $by);
        Mail::to($email)->send(new InvitationMail($invitation, route('invitations.show', $token)));

        return $invitation;
    }

    public function revoke(Invitation $invitation, User $by): void
    {
        $this->assertCanGrant($by, $invitation->tenant, $invitation->role);
        $invitation->delete();
    }

    public function changeRole(Membership $m, string $role, User $by): void
    {
        $this->assertCanManageMember($by, $m);
        $this->assertCanGrant($by, $m->tenant, $role);
        if ($m->role === User::ROLE_OWNER && $role !== User::ROLE_OWNER) {
            $this->assertNotLastOwner($m);
        }
        $m->update(['role' => $role]);
    }

    public function remove(Membership $m, User $by): void
    {
        $this->assertCanManageMember($by, $m);
        if ($m->role === User::ROLE_OWNER) {
            $this->assertNotLastOwner($m);
        }
        $m->delete();
    }

    /** Accept an invitation as $user (already signed in). */
    public function accept(Invitation $invitation, User $user): Membership
    {
        if (! $invitation->isUsable()) {
            throw new TeamRuleException('This invitation has expired or was already used.');
        }
        if (strtolower($user->email) !== strtolower($invitation->email)) {
            throw new TeamRuleException("This invitation is for {$invitation->email}.");
        }

        return DB::transaction(function () use ($invitation, $user) {
            $invitation->forceFill(['accepted_at' => now()])->save();
            $existing = Membership::where('tenant_id', $invitation->tenant_id)->where('user_id', $user->id)->first();
            $m = $existing ?? Membership::create(['tenant_id' => $invitation->tenant_id, 'user_id' => $user->id, 'role' => $invitation->role]);
            Audit::log('invitation.accepted', "{$user->email} accepted an invitation as {$invitation->role}", $invitation, $invitation->tenant_id);

            return $m;
        });
    }

    private function assertCanGrant(User $by, Tenant $tenant, string $role): void
    {
        if (! array_key_exists($role, self::ROLES)) {
            throw new TeamRuleException('Unknown role.');
        }
        if (! array_key_exists($role, $this->assignableRoles($by, $tenant))) {
            throw new TeamRuleException('Only owners can manage owners.');
        }
    }

    private function assertCanManageMember(User $by, Membership $m): void
    {
        if ($m->user_id === $by->id) {
            throw new TeamRuleException('You can\'t change your own membership. Ask another owner.');
        }
        $byRole = $by->roleIn($m->tenant);
        if (! in_array($byRole, [User::ROLE_OWNER, User::ROLE_ADMIN], true)) {
            throw new TeamRuleException('Only owners and admins can manage the team.');
        }
        if ($m->role === User::ROLE_OWNER && $byRole !== User::ROLE_OWNER) {
            throw new TeamRuleException('Only owners can manage owners.');
        }
    }

    private function assertNotLastOwner(Membership $m): void
    {
        $owners = Membership::where('tenant_id', $m->tenant_id)->where('role', User::ROLE_OWNER)->lockForUpdate()->count();
        if ($owners <= 1) {
            throw new TeamRuleException('Every organisation needs at least one owner. Make someone else an owner first.');
        }
    }
}
