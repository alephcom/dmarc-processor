<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Domains\Pages\CreateDomain;
use App\Filament\App\Resources\Domains\Pages\ListDomains;
use App\Filament\App\Resources\Domains\Pages\ViewDomain;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    private function member(Tenant $tenant, string $role = User::ROLE_ADMIN): User
    {
        $u = User::factory()->create();
        $u->tenants()->attach($tenant, ['role' => $role]);

        return $u;
    }

    private function useTenant(Tenant $tenant): void
    {
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tenant);
        Filament::bootCurrentPanel();
    }

    public function test_admin_panel_requires_super_admin(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAs($this->member($tenant))->get('/admin')->assertForbidden();

        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true])->save();
        // Super-admins must enrol MFA first, so they are redirected to set-up rather than forbidden.
        $this->actingAs($admin)->get('/admin')->assertRedirectContains('multi-factor-authentication');
    }

    public function test_user_cannot_open_another_tenants_workspace(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $user = $this->member($a);

        $this->actingAs($user)->get("/app/{$a->slug}")->assertOk();
        $this->actingAs($user)->get("/app/{$b->slug}")->assertNotFound();
    }

    public function test_domain_list_is_scoped_to_current_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $mine = Domain::factory()->for($a)->create(['name' => 'mine.example']);
        $theirs = Domain::factory()->for($b)->create(['name' => 'theirs.example']);
        $user = $this->member($a);

        $this->actingAs($user);
        $this->useTenant($a);

        Livewire::test(ListDomains::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);

        $this->get(ViewDomain::getUrl(['record' => $theirs], tenant: $a))->assertNotFound();
    }

    public function test_admin_can_add_domain_and_viewer_cannot(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAs($this->member($tenant, User::ROLE_ADMIN));
        $this->useTenant($tenant);

        Livewire::test(CreateDomain::class)
            ->fillForm(['name' => 'New-Domain.Example.'])
            ->call('create')
            ->assertHasNoFormErrors();

        $d = Domain::sole();
        $this->assertSame('new-domain.example', $d->name);
        $this->assertSame($tenant->id, $d->tenant_id);
        $this->assertMatchesRegularExpression('/^[a-z2-7]{10}$/', $d->rua_token);

        $this->actingAs($this->member($tenant, User::ROLE_VIEWER));
        $this->get(CreateDomain::getUrl(tenant: $tenant))->assertForbidden();
    }

    public function test_domain_validation(): void
    {
        $tenant = Tenant::factory()->create();
        Domain::factory()->create(['name' => 'taken.example']);
        $this->actingAs($this->member($tenant));
        $this->useTenant($tenant);

        foreach (['not a domain', 'localhost', '-bad.example'] as $bad) {
            Livewire::test(CreateDomain::class)->fillForm(['name' => $bad])->call('create')->assertHasFormErrors(['name' => 'regex']);
        }
        Livewire::test(CreateDomain::class)->fillForm(['name' => 'taken.example'])->call('create')->assertHasFormErrors(['name' => 'unique']);
    }
}
