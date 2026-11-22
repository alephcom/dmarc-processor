<?php

namespace Tests\Feature;

use App\Dns\ArrayResolver;
use App\Dns\Resolver;
use App\Filament\App\Resources\AuditLogs\AuditLogResource;
use App\Filament\App\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Membership;
use App\Models\NotificationChannel;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DomainScanner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->owner = User::factory()->create();
        Membership::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id, 'role' => 'owner']);
    }

    private function actAs(User $u, ?Tenant $t = null): void
    {
        $this->actingAs($u);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($t ?? $this->tenant);
        Filament::bootCurrentPanel();
    }

    public function test_user_changes_are_logged_with_tenant_and_diff(): void
    {
        $this->actingAs($this->owner);
        $d = Domain::factory()->for($this->tenant)->create(['name' => 'acme.test']);
        $d->update(['target_policy' => 'quarantine']);

        $log = AuditLog::where('action', 'domain.updated')->sole();
        $this->assertSame($this->tenant->id, $log->tenant_id);
        $this->assertSame($this->owner->id, $log->actor_id);
        $this->assertSame(['target_policy' => ['reject', 'quarantine']], $log->changes);
        $this->assertSame('Updated domain acme.test', $log->description);
    }

    public function test_system_changes_are_not_logged(): void
    {
        $d = Domain::factory()->for($this->tenant)->create(['name' => 'acme.test']);
        $this->app->instance(Resolver::class, new ArrayResolver);
        app(DomainScanner::class)->scan($d);
        $d->update(['status' => 'active']);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_secrets_are_never_recorded(): void
    {
        $this->actingAs($this->owner);
        $ch = NotificationChannel::create(['tenant_id' => $this->tenant->id, 'name' => 'Hook', 'type' => 'webhook', 'config' => ['url' => 'https://h.example.com/x', 'secret' => 'super-secret-value']]);
        $ch->update(['config' => ['url' => 'https://h.example.com/y', 'secret' => 'another-secret-value']]);
        $this->owner->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->owner->update(['password' => 'new-password-123456']);

        $all = AuditLog::all()->toJson();
        foreach (['super-secret-value', 'another-secret-value', 'h.example.com/y', 'JBSWY3DPEHPK3PXP', 'new-password'] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
        $this->assertSame(['(hidden)', '(changed)'], AuditLog::where('action', 'notification_channel.updated')->sole()->changes['config']);
        $this->assertTrue(AuditLog::where('action', 'user.mfa_enabled')->exists());
    }

    public function test_sign_ins_are_logged(): void
    {
        $this->post('/app/login'); // warm session
        auth()->attempt(['email' => $this->owner->email, 'password' => 'wrong']);
        auth()->attempt(['email' => $this->owner->email, 'password' => 'password']);
        $this->assertSame(['auth.failed', 'auth.login'], AuditLog::orderBy('id')->pluck('action')->all());
    }

    public function test_activity_page_is_manager_only_and_tenant_scoped(): void
    {
        $this->actingAs($this->owner);
        Domain::factory()->for($this->tenant)->create(['name' => 'mine.test']);
        $other = Tenant::factory()->create();
        Domain::factory()->for($other)->create(['name' => 'theirs.test']);
        $mine = AuditLog::where('description', 'like', '%mine.test')->sole();
        $theirs = AuditLog::where('description', 'like', '%theirs.test')->sole();

        $viewer = User::factory()->create();
        Membership::create(['tenant_id' => $this->tenant->id, 'user_id' => $viewer->id, 'role' => 'viewer']);
        $this->actAs($viewer);
        $this->get(AuditLogResource::getUrl('index', tenant: $this->tenant))->assertForbidden();

        $this->actAs($this->owner);
        Livewire::test(ListAuditLogs::class)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$theirs]);
    }
}
