<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Filament\Admin\Resources\Tenants\RelationManagers\MembershipsRelationManager;
use App\Filament\App\Resources\Memberships\MembershipResource;
use App\Filament\App\Resources\Memberships\Pages\ListMemberships;
use App\Filament\App\Resources\Memberships\Widgets\PendingInvitations;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Team\InvitationMail;
use App\Team\TeamRuleException;
use App\Team\TeamService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class TeamTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->tenant = Tenant::factory()->create(['name' => 'Acme']);
    }

    private function member(string $role, ?Tenant $t = null): User
    {
        $u = User::factory()->create();
        Membership::create(['tenant_id' => ($t ?? $this->tenant)->id, 'user_id' => $u->id, 'role' => $role]);

        return $u;
    }

    private function membership(User $u): Membership
    {
        return Membership::where('user_id', $u->id)->where('tenant_id', $this->tenant->id)->sole();
    }

    private function actAs(User $u, ?Tenant $t = null): void
    {
        $this->actingAs($u);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($t ?? $this->tenant);
        Filament::bootCurrentPanel();
    }

    public function test_role_rules(): void
    {
        $team = app(TeamService::class);
        $owner = $this->member('owner');
        $admin = $this->member('admin');
        $viewer = $this->member('viewer');

        // Admins manage viewers/admins but not owners, and can't create owners.
        $team->changeRole($this->membership($viewer), 'admin', $admin);
        $this->assertSame('admin', $this->membership($viewer)->role);
        $this->assertThrows(fn () => $team->changeRole($this->membership($viewer), 'owner', $admin), TeamRuleException::class);
        $this->assertThrows(fn () => $team->remove($this->membership($owner), $admin), TeamRuleException::class, 'Only owners can manage owners.');
        $this->assertThrows(fn () => $team->invite($this->tenant, 'x@y.test', 'owner', $admin), TeamRuleException::class);

        // Nobody changes themselves; the last owner can't be removed or demoted.
        $this->assertThrows(fn () => $team->changeRole($this->membership($admin), 'viewer', $admin), TeamRuleException::class);
        $owner2 = $this->member('owner');
        $team->changeRole($this->membership($owner2), 'admin', $owner);
        $this->assertThrows(fn () => $team->remove($this->membership($owner), $owner2), TeamRuleException::class);
        $this->assertThrows(fn () => $team->changeRole($this->membership($owner), 'viewer', User::factory()->create()->forceFill(['is_super_admin' => true])), TeamRuleException::class, 'at least one owner');

        // Viewers can't do anything.
        $freshViewer = $this->member('viewer');
        $this->assertThrows(fn () => $team->invite($this->tenant, 'x@y.test', 'viewer', $freshViewer), TeamRuleException::class);
    }

    public function test_team_page_is_manager_only_and_tenant_scoped(): void
    {
        $owner = $this->member('owner');
        $other = Tenant::factory()->create();
        $outsider = $this->member('owner', $other);
        $outsiderMembership = Membership::where('user_id', $outsider->id)->sole();
        $ownerMembership = $this->membership($owner);

        $this->actAs($this->member('viewer'));
        $this->get(MembershipResource::getUrl('index', tenant: $this->tenant))->assertForbidden();

        $this->actAs($owner);
        Livewire::test(ListMemberships::class)
            ->assertCanSeeTableRecords([$ownerMembership])
            ->assertCanNotSeeTableRecords([$outsiderMembership]);
    }

    public function test_invite_change_role_and_remove_through_ui(): void
    {
        $owner = $this->member('owner');
        $viewer = $this->member('viewer');
        $this->actAs($owner);

        Livewire::test(ListMemberships::class)
            ->callTableAction('invite', data: ['email' => 'New.Person@Acme.test', 'role' => 'admin'])
            ->assertNotified('Invitation sent to New.Person@Acme.test');
        $inv = Invitation::sole();
        $this->assertSame('new.person@acme.test', $inv->email);
        Mail::assertSent(InvitationMail::class, fn ($m) => $m->hasTo('new.person@acme.test') && str_contains($m->url, '/invitations/')
            && ! str_contains($m->render(), 'tech support')); // regular MSP customers don't get the free-service note

        Livewire::test(ListMemberships::class)
            ->callTableAction('role', $this->membership($viewer), data: ['role' => 'admin']);
        $this->assertSame('admin', $this->membership($viewer)->role);

        Livewire::test(ListMemberships::class)->callTableAction('remove', $this->membership($viewer));
        $this->assertSame(0, Membership::where('user_id', $viewer->id)->count());

        Livewire::test(PendingInvitations::class)->assertCanSeeTableRecords([$inv])->callTableAction('revoke', $inv);
        $this->assertSame(0, Invitation::count());
    }

    public function test_cannot_invite_existing_member_and_reinvite_replaces_link(): void
    {
        $owner = $this->member('owner');
        $team = app(TeamService::class);
        $this->assertThrows(fn () => $team->invite($this->tenant, $owner->email, 'viewer', $owner), TeamRuleException::class);

        $team->invite($this->tenant, 'a@acme.test', 'viewer', $owner);
        $team->invite($this->tenant, 'a@acme.test', 'admin', $owner);
        $this->assertSame('admin', Invitation::sole()->role);
    }

    public function test_msp_can_manage_members_of_any_tenant(): void
    {
        $owner = $this->member('owner');
        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true])->save();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(MembershipsRelationManager::class, ['ownerRecord' => $this->tenant, 'pageClass' => EditTenant::class])
            ->assertCanSeeTableRecords([$this->membership($owner)])
            ->callTableAction('invite', data: ['email' => 'owner2@acme.test', 'role' => 'owner']);
        $this->assertSame('owner', Invitation::sole()->role);
    }
}
