<?php

namespace Tests\Feature;

use App\Audit\Audit;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Team\Impersonation;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['email' => 'ops@msp.test']);
        $this->admin->forceFill(['is_super_admin' => true])->save();
        $this->tenant = Tenant::factory()->create(['slug' => 'acme']);
        $this->customer = User::factory()->create(['email' => 'pat@acme.test']);
        Membership::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->customer->id, 'role' => 'admin']);
    }

    public function test_rules(): void
    {
        $other = User::factory()->create();
        $other->forceFill(['is_super_admin' => true])->save();

        $this->assertTrue(Impersonation::canImpersonate($this->admin, $this->customer));
        $this->assertFalse(Impersonation::canImpersonate($this->admin, $other), 'not other super-admins');
        $this->assertFalse(Impersonation::canImpersonate($this->admin, $this->admin), 'not yourself');
        $this->assertFalse(Impersonation::canImpersonate($this->customer, $this->admin), 'customers cannot');
    }

    public function test_start_from_admin_banner_and_return(): void
    {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListUsers::class)->callTableAction('impersonate', $this->customer)->assertRedirect('/app');

        $this->assertAuthenticatedAs($this->customer);
        $this->assertSame($this->admin->id, session(Audit::IMPERSONATOR_SESSION_KEY));
        // The session hash matches the new user, so AuthenticateSession won't log them out.
        $this->get('/app/acme')->assertOk()->assertSee('are signed in as')->assertSee('pat@acme.test')->assertSee('Return to MSP admin');
        $this->assertAuthenticatedAs($this->customer);

        // Actions while impersonating are attributed to both.
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->tenant);
        Domain::factory()->for($this->tenant)->create(['name' => 'acme.test']);
        $log = AuditLog::where('action', 'domain.created')->sole();
        $this->assertSame($this->customer->id, $log->actor_id);
        $this->assertSame($this->admin->id, $log->impersonator_id);

        $this->post('/impersonation/leave')->assertRedirect('/admin/users');
        $this->assertAuthenticatedAs($this->admin);
        $this->assertNull(session(Audit::IMPERSONATOR_SESSION_KEY));
        $this->assertSame(['impersonation.started', 'impersonation.ended'], AuditLog::where('action', 'like', 'impersonation.%')->orderBy('id')->pluck('action')->all());
    }

    public function test_leave_without_impersonating_does_nothing(): void
    {
        $this->actingAs($this->customer)->post('/impersonation/leave')->assertRedirect('/app');
        $this->assertAuthenticatedAs($this->customer);
    }

    public function test_forged_session_cannot_escalate(): void
    {
        // A customer who somehow sets the session key to a non-admin id can't "return" to that user.
        $victim = User::factory()->create();
        $this->actingAs($this->customer)->withSession([Audit::IMPERSONATOR_SESSION_KEY => $victim->id])->post('/impersonation/leave');
        $this->assertAuthenticatedAs($this->customer);
    }

    public function test_no_banner_normally(): void
    {
        $this->actingAs($this->customer)->get('/app/acme')->assertOk()->assertDontSee('Return to MSP admin');
    }
}
