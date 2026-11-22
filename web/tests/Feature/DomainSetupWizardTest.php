<?php

namespace Tests\Feature;

use App\Dns\ArrayResolver;
use App\Dns\Resolver;
use App\Filament\App\Resources\Domains\DomainResource;
use App\Filament\App\Resources\Domains\Pages\CreateDomain;
use App\Filament\App\Resources\Domains\Pages\SetupDomain;
use App\Models\DnsCheck;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DomainSetupWizardTest extends TestCase
{
    use RefreshDatabase;

    private ArrayResolver $dns;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['dmarc.reports_domain' => 'reports.msp.test']);
        $this->dns = new ArrayResolver;
        $this->app->instance(Resolver::class, $this->dns);

        $this->tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $user->tenants()->attach($this->tenant, ['role' => User::ROLE_ADMIN]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->tenant);
        Filament::bootCurrentPanel();
    }

    private function domain(array $attrs = []): Domain
    {
        $d = Domain::factory()->for($this->tenant)->create(['name' => 'example.com'] + $attrs);
        $this->dns->set('example.com._report._dmarc.reports.msp.test', 'TXT', ['v=DMARC1']);

        return $d;
    }

    private function publishOurRecord(Domain $d): void
    {
        $this->dns->set('_dmarc.example.com', 'TXT', ['v=DMARC1; p=none; rua=mailto:'.$d->ruaAddress()]);
    }

    public function test_creating_a_domain_opens_the_wizard(): void
    {
        Livewire::test(CreateDomain::class)
            ->fillForm(['name' => 'example.com'])
            ->call('create')
            ->assertRedirect(DomainResource::getUrl('setup', ['record' => Domain::sole()], tenant: $this->tenant));
    }

    public function test_first_visit_scans_after_render_and_shows_overview(): void
    {
        $d = $this->domain();
        $this->dns->set('example.com', 'TXT', ['v=spf1 include:_spf.google.com -all'])
            ->set('_spf.google.com', 'TXT', ['v=spf1 ip4:192.0.2.0/24 ~all'])
            ->set('example.com', 'NS', ['dana.ns.cloudflare.com']);

        $page = Livewire::test(SetupDomain::class, ['record' => $d->getRouteKey()])
            ->assertSee('Checking DNS records for example.com');
        $this->assertSame(0, DnsCheck::count());

        $page->call('initialScan')
            ->assertSee('DMARC: not found')
            ->assertSee('Valid, using 1 of 10 DNS lookups')
            ->assertSee('hosted at Cloudflare');

        $this->assertSame(4, DnsCheck::count());
        $this->assertSame('cloudflare', $d->fresh()->dns_provider);
    }

    public function test_dmarc_step_builds_merged_record_with_provider_instructions(): void
    {
        $d = $this->domain(['onboarding_step' => 'dmarc']);
        $this->dns->set('_dmarc.example.com', 'TXT', ['v=DMARC1; p=quarantine; rua=mailto:old@example.com'])
            ->set('example.com', 'NS', ['ns1.domaincontrol.com']);

        Livewire::test(SetupDomain::class, ['record' => $d->getRouteKey()])
            ->call('rescan')
            ->assertWizardCurrentStep(4)
            ->assertSee('You already have a DMARC record')
            ->assertSee('v=DMARC1; p=quarantine; rua=mailto:old@example.com,mailto:'.$d->ruaAddress())
            ->assertSee('How to add it in GoDaddy');
    }

    public function test_verify_step_detects_published_record(): void
    {
        $d = $this->domain(['onboarding_step' => 'verify']);
        $page = Livewire::test(SetupDomain::class, ['record' => $d->getRouteKey()])
            ->call('rescan')
            ->assertWizardCurrentStep(5)
            ->assertSee('We can\'t see the updated record yet');
        $this->assertNull($d->fresh()->dns_verified_at);

        $this->publishOurRecord($d);
        $page->call('pollVerification')->assertSee('Your DMARC record is live');

        $d->refresh();
        $this->assertNotNull($d->dns_verified_at);
        $this->assertSame('none', $d->current_policy);
    }

    public function test_steps_advance_and_progress_is_saved(): void
    {
        $d = $this->domain();
        $this->publishOurRecord($d);

        $page = Livewire::test(SetupDomain::class, ['record' => $d->getRouteKey()])->call('initialScan');
        $page->goToNextWizardStep()->assertWizardCurrentStep(2);
        $this->assertSame('spf', $d->fresh()->onboarding_step);

        $page->goToNextWizardStep()->assertWizardCurrentStep(3);
        $this->assertSame('dkim', $d->fresh()->onboarding_step);

        // Resuming later starts on the saved step.
        Livewire::test(SetupDomain::class, ['record' => $d->getRouteKey()])->assertWizardCurrentStep(3);
    }

    public function test_extra_dkim_selectors_are_saved_and_checked(): void
    {
        $d = $this->domain(['onboarding_step' => 'dkim']);
        $this->dns->set('custom1._domainkey.example.com', 'TXT', ['v=DKIM1; k=ed25519; p=11qYAYKxCrfVS/7TyWQHOg7hcvPapiMlrwIaaPcHURo=']);

        Livewire::test(SetupDomain::class, ['record' => $d->getRouteKey()])
            ->call('initialScan')
            ->assertWizardCurrentStep(3)
            ->fillForm(['dkim_selectors' => ['Custom1']])
            ->goToNextWizardStep()
            ->assertHasNoFormErrors()
            ->assertWizardCurrentStep(4);

        $d->refresh();
        $this->assertSame(['custom1'], $d->dkim_selectors);
        $this->assertSame('dmarc', $d->onboarding_step);
        $this->assertSame('custom1', $d->latestChecks()['dkim']->parsed['keys'][0]['selector']);
    }

    public function test_invalid_selector_is_rejected(): void
    {
        $d = $this->domain(['onboarding_step' => 'dkim']);
        Livewire::test(SetupDomain::class, ['record' => $d->getRouteKey()])
            ->fillForm(['dkim_selectors' => ['bad selector!']])
            ->goToNextWizardStep()
            ->assertHasFormErrors()
            ->assertWizardCurrentStep(3);
    }

    public function test_missing_msp_authorisation_is_flagged(): void
    {
        $d = Domain::factory()->for($this->tenant)->create(['name' => 'example.com']);
        Livewire::test(SetupDomain::class, ['record' => $d->getRouteKey()])
            ->call('initialScan')
            ->assertSee('Our reporting domain is not yet authorised');
    }

    public function test_finish_marks_setup_done(): void
    {
        $d = $this->domain(['onboarding_step' => 'waiting']);
        Livewire::test(SetupDomain::class, ['record' => $d->getRouteKey()])
            ->call('finish')
            ->assertRedirect(DomainResource::getUrl('view', ['record' => $d], tenant: $this->tenant));
        $this->assertSame('done', $d->fresh()->onboarding_step);
    }

    public function test_viewers_and_other_tenants_cannot_open_the_wizard(): void
    {
        $d = $this->domain();

        $viewer = User::factory()->create();
        $viewer->tenants()->attach($this->tenant, ['role' => User::ROLE_VIEWER]);
        $this->actingAs($viewer)->get(DomainResource::getUrl('setup', ['record' => $d], tenant: $this->tenant))->assertForbidden();

        $other = Tenant::factory()->create();
        $outsider = User::factory()->create();
        $outsider->tenants()->attach($other, ['role' => User::ROLE_OWNER]);
        $this->actingAs($outsider)->get(DomainResource::getUrl('setup', ['record' => $d], tenant: $other))->assertNotFound();
    }

    public function test_rescans_are_rate_limited(): void
    {
        $d = $this->domain();
        $page = Livewire::test(SetupDomain::class, ['record' => $d->getRouteKey()]);
        for ($i = 0; $i < 5; $i++) {
            $page->call('rescan');
        }
        $this->assertSame(3 * 4, DnsCheck::count()); // 3 scans x 4 check types
    }
}
