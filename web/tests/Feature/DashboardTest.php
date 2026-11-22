<?php

namespace Tests\Feature;

use App\Filament\Admin\Widgets\AllDomainsHealth;
use App\Filament\Admin\Widgets\MspStatsOverview;
use App\Filament\App\Resources\Domains\Pages\ViewDomain;
use App\Filament\App\Resources\Domains\Widgets\DomainReportersTable;
use App\Filament\App\Resources\Domains\Widgets\DomainSourcesTable;
use App\Filament\App\Resources\Domains\Widgets\DomainStatsOverview;
use App\Filament\App\Resources\Domains\Widgets\DomainVolumeChart;
use App\Filament\App\Widgets\GettingStarted;
use App\Filament\App\Widgets\TenantDomainHealth;
use App\Filament\App\Widgets\TenantStatsOverview;
use App\Filament\App\Widgets\TenantVolumeChart;
use App\Intel\SourceRegistry;
use App\Models\Domain;
use App\Models\DomainSource;
use App\Models\IpIntel;
use App\Models\KnownSender;
use App\Models\Report;
use App\Models\Tenant;
use App\Models\User;
use App\Services\StatsAggregator;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['name' => 'Acme']);
        $this->domain = Domain::factory()->for($this->tenant)->create(['name' => 'acme.test', 'status' => 'active']);
    }

    private function actAs(string $role, ?Tenant $tenant = null): User
    {
        $u = User::factory()->create();
        $u->tenants()->attach($tenant ?? $this->tenant, ['role' => $role]);
        $this->actingAs($u);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tenant ?? $this->tenant);
        Filament::bootCurrentPanel();

        return $u;
    }

    /** Mail from Google that passes, and an unknown server that fails. */
    private function seedTraffic(Domain $d): void
    {
        $google = KnownSender::firstWhere('name', 'Google Workspace');
        IpIntel::create(['ip' => '209.85.220.41', 'asn' => 15169, 'as_org' => 'Google LLC', 'known_sender_id' => $google->id, 'match_reason' => 'asn', 'refreshed_at' => now()]);
        IpIntel::create(['ip' => '203.0.113.9', 'asn' => 64511, 'as_org' => 'Shady Hosting', 'refreshed_at' => now()]);
        $day = now()->subDays(2)->toDateString();
        $report = Report::create([
            'domain_id' => $d->id, 'reporter_org' => 'google.com', 'report_id' => uniqid(), 'policy_domain' => $d->name,
            'date_begin' => $day, 'date_end' => $day, 'policy_published' => [], 'message_count' => 105,
        ]);
        $row = fn ($ip, $count, $pass, $dkim, $spf) => [
            'report_id' => $report->id, 'domain_id' => $d->id, 'day' => $day, 'source_ip' => $ip, 'count' => $count,
            'disposition' => 'none', 'dkim_aligned' => $pass, 'spf_aligned' => $pass, 'header_from' => $d->name,
            'dkim_results' => json_encode($dkim), 'spf_results' => json_encode($spf), 'reasons' => null,
        ];
        DB::table('report_records')->insert([
            $row('209.85.220.41', 100, true, [['domain' => $d->name, 'selector' => 'google', 'result' => 'pass']], [['domain' => $d->name, 'result' => 'pass']]),
            $row('203.0.113.9', 5, false, [], [['domain' => 'spoof.example', 'result' => 'fail']]),
        ]);
        app(StatsAggregator::class)->refreshDay($d->id, $day);
        app(SourceRegistry::class)->sync($d->id);
    }

    public function test_domain_page_shows_stats_chart_and_senders(): void
    {
        $this->seedTraffic($this->domain);
        $this->actAs(User::ROLE_ADMIN);

        $this->get(ViewDomain::getUrl(['record' => $this->domain], tenant: $this->tenant))->assertOk();

        Livewire::test(DomainStatsOverview::class, ['record' => $this->domain, 'days' => 30])
            ->assertSee('105')->assertSee('95.2%')->assertSee('Senders to review');

        $chart = Livewire::test(DomainVolumeChart::class, ['record' => $this->domain, 'days' => 30]);
        $data = (fn () => $this->getData())->call($chart->instance());
        $this->assertCount(30, $data['labels']);
        $this->assertSame(100, array_sum($data['datasets'][0]['data']));
        $this->assertSame(5, array_sum($data['datasets'][1]['data']));

        $google = DomainSource::where('label', 'Google Workspace')->sole();
        $shady = DomainSource::where('label', 'AS64511 Shady Hosting')->sole();
        Livewire::test(DomainSourcesTable::class, ['record' => $this->domain, 'days' => 30])
            ->assertCanSeeTableRecords([$google, $shady])
            ->assertSee('100%')
            ->assertSee('0%');

        Livewire::test(DomainReportersTable::class, ['record' => $this->domain, 'days' => 30])->assertSee('google.com');
    }

    public function test_period_excludes_old_data(): void
    {
        $this->seedTraffic($this->domain);
        DB::table('daily_source_stats')->update(['day' => now()->subDays(20)->toDateString()]);
        $this->actAs(User::ROLE_ADMIN);
        Livewire::test(DomainSourcesTable::class, ['record' => $this->domain, 'days' => 7])->assertCountTableRecords(0);
        Livewire::test(DomainSourcesTable::class, ['record' => $this->domain, 'days' => 30])->assertCountTableRecords(2);
    }

    public function test_admin_can_classify_but_viewer_cannot(): void
    {
        $this->seedTraffic($this->domain);
        $shady = DomainSource::where('label', 'AS64511 Shady Hosting')->sole();

        $this->actAs(User::ROLE_ADMIN);
        Livewire::test(DomainSourcesTable::class, ['record' => $this->domain, 'days' => 30])
            ->call('updateTableColumnState', 'classification', (string) $shady->getKey(), 'suspicious');
        $this->assertSame('suspicious', $shady->fresh()->classification);

        $this->actAs(User::ROLE_VIEWER);
        Livewire::test(DomainSourcesTable::class, ['record' => $this->domain, 'days' => 30])
            ->call('updateTableColumnState', 'classification', (string) $shady->getKey(), 'legit');
        $this->assertSame('suspicious', $shady->fresh()->classification);
    }

    public function test_source_details_explain_what_to_do(): void
    {
        $this->seedTraffic($this->domain);
        $this->actAs(User::ROLE_ADMIN);
        $google = DomainSource::where('label', 'Google Workspace')->sole();
        $shady = DomainSource::where('label', 'AS64511 Shady Hosting')->sole();

        Livewire::test(DomainSourcesTable::class, ['record' => $this->domain, 'days' => 30])
            ->mountTableAction('details', $google)
            ->assertMountedActionModalSee(['passes DKIM with your domain', '209.85.220.41']);

        Livewire::test(DomainSourcesTable::class, ['record' => $this->domain, 'days' => 30])
            ->mountTableAction('details', $shady)
            ->assertMountedActionModalSee(['If you don\'t recognise this sender, mark it as suspicious', 'spoof.example']);
    }

    public function test_tenant_dashboard_only_shows_own_domains(): void
    {
        $this->seedTraffic($this->domain);
        $other = Domain::factory()->for(Tenant::factory())->create(['name' => 'other.test']);
        $this->actAs(User::ROLE_VIEWER);

        $this->get("/app/{$this->tenant->slug}")->assertOk();
        Livewire::test(TenantDomainHealth::class)
            ->assertCanSeeTableRecords([$this->domain])
            ->assertCanNotSeeTableRecords([$other]);
        Livewire::test(TenantStatsOverview::class)->assertSee('105')->assertSee('95.2%');
    }

    public function test_msp_dashboard_shows_all_tenants(): void
    {
        $this->seedTraffic($this->domain);
        $other = Domain::factory()->for(Tenant::factory())->create(['name' => 'other.test']);
        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true])->save();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(AllDomainsHealth::class)->assertCanSeeTableRecords([$this->domain, $other])->assertSee('Acme');
        Livewire::test(MspStatsOverview::class)->assertSee('0 / 2 live');
    }

    public function test_stat_cards_compare_with_previous_period(): void
    {
        $this->seedTraffic($this->domain);
        DB::table('daily_domain_stats')->insert([
            'domain_id' => $this->domain->id, 'day' => now()->subDays(40)->toDateString(), 'total' => 50, 'dmarc_pass' => 50,
            'spf_aligned' => 50, 'dkim_aligned' => 50, 'quarantined' => 0, 'rejected' => 0,
        ]);
        $this->actAs(User::ROLE_VIEWER);

        // 105 messages vs 50 before; 95.2% passing vs 100% before.
        Livewire::test(TenantStatsOverview::class)
            ->assertSee('+110% vs prior period')
            ->assertSee('-4.8 pts vs prior period');
    }

    public function test_stat_cards_without_previous_data(): void
    {
        $this->seedTraffic($this->domain);
        $this->actAs(User::ROLE_VIEWER);

        Livewire::test(TenantStatsOverview::class)
            ->assertSee('Nothing to compare yet')
            ->assertSee('5 failing');
    }

    public function test_empty_workspace_shows_getting_started(): void
    {
        $empty = Tenant::factory()->create();
        $this->actAs(User::ROLE_ADMIN, $empty);

        $this->get("/app/{$empty->slug}")->assertOk()->assertSee('Add your first domain');
        $this->assertTrue(GettingStarted::canView());
        $this->assertFalse(TenantStatsOverview::canView());
        $this->assertFalse(TenantDomainHealth::canView());
        $this->assertFalse(TenantVolumeChart::canView());
        Livewire::test(GettingStarted::class)->assertSee('Add a domain');

        $this->actAs(User::ROLE_VIEWER, $empty);
        Livewire::test(GettingStarted::class)->assertSee('Ask an owner or admin')->assertDontSee('Add a domain');
    }

    public function test_getting_started_waits_for_first_report(): void
    {
        // Another tenant's reports must not hide this tenant's empty state.
        Domain::factory()->for(Tenant::factory())->create(['first_report_at' => now()]);
        $this->actAs(User::ROLE_ADMIN);

        $this->assertTrue(GettingStarted::canView());
        $this->assertTrue(TenantStatsOverview::canView());
        $this->assertFalse(TenantVolumeChart::canView());
        Livewire::test(GettingStarted::class)->assertSee('Waiting for the first reports')->assertSee('acme.test')->assertSee('Continue setup');

        $this->domain->forceFill(['dns_verified_at' => now()])->save();
        Livewire::test(GettingStarted::class)->assertSee('within 24 to 48 hours')->assertDontSee('Continue setup');

        $this->domain->update(['first_report_at' => now()]);
        $this->assertFalse(GettingStarted::canView());
        $this->assertTrue(TenantVolumeChart::canView());
        $this->get("/app/{$this->tenant->slug}")->assertOk()->assertDontSee('Waiting for the first reports');
    }
}
