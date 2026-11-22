<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\DomainSource;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Reports\ShareLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsTraffic;
use Tests\TestCase;

class PrintableReportTest extends TestCase
{
    use BuildsTraffic, RefreshDatabase;

    private Tenant $tenant;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        config(['dmarc.brand_name' => 'Example MSP Ltd']);
        $this->tenant = Tenant::factory()->create(['name' => 'Acme']);
        $this->domain = Domain::factory()->for($this->tenant)->create(['name' => 'acme.test']);
        $this->traffic($this->domain, '209.85.220.41', $this->daysAgo(2), 950, 50);
        DomainSource::query()->update(['label' => 'Google Workspace', 'classification' => 'legit']);
    }

    private function member(string $role = 'viewer', ?Tenant $t = null): User
    {
        $u = User::factory()->create();
        Membership::create(['tenant_id' => ($t ?? $this->tenant)->id, 'user_id' => $u->id, 'role' => $role]);

        return $u;
    }

    public function test_domain_report_for_members(): void
    {
        $this->actingAs($this->member())->get(route('reports.domain', [$this->domain, 'period' => 30]))
            ->assertOk()
            ->assertSee('Example MSP Ltd')
            ->assertSee('acme.test')
            ->assertSee('1,000')
            ->assertSee('95%')
            ->assertSee('Google Workspace')
            ->assertSee('<svg', false)
            ->assertSee('Print / Save as PDF');
    }

    public function test_tenant_report(): void
    {
        $this->actingAs($this->member())->get(route('reports.tenant', [$this->tenant]))
            ->assertOk()->assertSee('Acme')->assertSee('acme.test')->assertSee('0 / 1')->assertSee('not live');
    }

    public function test_other_tenants_and_guests_cannot_view(): void
    {
        $outsider = $this->member('owner', Tenant::factory()->create());
        $this->actingAs($outsider)->get(route('reports.domain', [$this->domain]))->assertNotFound();
        $this->actingAs($outsider)->get(route('reports.tenant', [$this->tenant]))->assertNotFound();
        auth()->logout();
        $this->get(route('reports.domain', [$this->domain]))->assertRedirect();
    }

    public function test_share_links_work_without_login_and_can_be_revoked(): void
    {
        $url = ShareLinks::domain($this->domain, 30);
        $this->get($url)->assertOk()->assertSee('acme.test')->assertDontSee('>Back<', false);

        $this->get(str_replace('period=30', 'period=365', $url))->assertForbidden(); // tampered
        $other = Domain::factory()->create();
        $this->get(str_replace('domains/'.$this->domain->id, 'domains/'.$other->id, $url))->assertForbidden(); // signature covers the domain

        $tenantUrl = ShareLinks::tenant($this->tenant, 30);
        $this->get($tenantUrl)->assertOk();

        ShareLinks::revokeAll($this->tenant);
        $this->get($url)->assertForbidden();
        $this->get($tenantUrl)->assertForbidden();
        $this->get(ShareLinks::domain($this->domain->fresh(), 30))->assertOk();

        $this->travel(15)->days();
        $this->get(ShareLinks::domain($this->domain->fresh(), 30))->assertOk(); // freshly made
        $old = ShareLinks::domain($this->domain->fresh(), 30);
        $this->travel(15)->days();
        $this->get($old)->assertForbidden(); // expired
    }
}
