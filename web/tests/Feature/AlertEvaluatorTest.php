<?php

namespace Tests\Feature;

use App\Alerts\AlertEvaluator;
use App\Alerts\AlertManager;
use App\Alerts\AlertSettings;
use App\Alerts\AlertType;
use App\Dns\ArrayResolver;
use App\Dns\Resolver;
use App\Jobs\DeliverAlert;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Domain;
use App\Models\DomainSource;
use App\Models\InboundMessage;
use App\Services\DomainScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsTraffic;
use Tests\TestCase;

class AlertEvaluatorTest extends TestCase
{
    use BuildsTraffic, RefreshDatabase;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([DeliverAlert::class]);
        $this->domain = Domain::factory()->create([
            'name' => 'acme.test', 'status' => Domain::STATUS_ACTIVE,
            'first_report_at' => now()->subDays(60), 'last_report_at' => now(),
        ]);
    }

    private function evaluator(): AlertEvaluator
    {
        return app(AlertEvaluator::class);
    }

    public function test_new_failing_sender_raises_warning_and_classifying_resolves_it(): void
    {
        $this->traffic($this->domain, '203.0.113.9', $this->daysAgo(0), 0, 40);
        $this->evaluator()->newSenders($this->domain);

        $alert = Alert::sole();
        $this->assertSame('new_sender', $alert->type);
        $this->assertSame('warning', $alert->severity);
        $this->assertStringContainsString('fails DMARC', $alert->body);
        Queue::assertPushed(DeliverAlert::class, 1);

        DomainSource::firstWhere('source_key', 'ip:203.0.113.9')->update(['classification' => 'suspicious']);
        $this->assertSame('manual', $alert->fresh()->resolution);
    }

    public function test_new_passing_sender_is_info(): void
    {
        $this->traffic($this->domain, '203.0.113.9', $this->daysAgo(0), 40);
        $this->evaluator()->newSenders($this->domain);
        $this->assertSame('info', Alert::sole()->severity);
    }

    public function test_no_new_sender_alerts_during_learning_period_or_below_threshold(): void
    {
        $this->domain->update(['first_report_at' => now()->subDays(3)]);
        $this->traffic($this->domain, '203.0.113.9', $this->daysAgo(0), 0, 40);
        $this->evaluator()->newSenders($this->domain);
        $this->assertSame(0, Alert::count());

        $this->domain->update(['first_report_at' => now()->subDays(60)]);
        $this->traffic($this->domain, '203.0.113.10', $this->daysAgo(0), 0, 3);
        $this->evaluator()->newSenders($this->domain->fresh());
        $this->assertSame(1, Alert::count()); // only the 40-message one, not the 3-message one
    }

    public function test_repeat_detection_does_not_duplicate_or_renotify(): void
    {
        $this->traffic($this->domain, '203.0.113.9', $this->daysAgo(0), 0, 40);
        $this->evaluator()->newSenders($this->domain);
        $this->evaluator()->newSenders($this->domain);
        $this->assertSame(1, Alert::count());
        Queue::assertPushed(DeliverAlert::class, 1);
    }

    public function test_new_sender_alerts_expire(): void
    {
        $this->traffic($this->domain, '203.0.113.9', $this->daysAgo(0), 0, 40);
        $this->evaluator()->newSenders($this->domain);
        $this->travel(8)->days();
        $this->evaluator()->expire();
        $this->assertSame('expired', Alert::sole()->resolution);
    }

    public function test_failure_spike_raises_and_clears(): void
    {
        foreach (range(7, 1) as $n) {
            $this->traffic($this->domain, '209.85.220.41', $this->daysAgo($n), 990, 10);
        }
        $this->traffic($this->domain, '203.0.113.9', $this->daysAgo(0), 600, 400);
        $this->evaluator()->failureSpike($this->domain);
        $alert = Alert::sole();
        $this->assertSame('failure_spike', $alert->type);
        $this->assertSame('critical', $alert->severity); // 39-point drop >= 2 x 10

        $this->traffic($this->domain, '209.85.220.41', $this->daysAgo(-1), 1000, 0);
        $this->evaluator()->failureSpike($this->domain);
        $this->assertSame('auto', $alert->fresh()->resolution);
    }

    public function test_legit_sender_failing_is_critical_and_resolves(): void
    {
        $this->traffic($this->domain, '209.85.220.41', $this->daysAgo(1), 100);
        DomainSource::firstWhere('source_key', 'ip:209.85.220.41')->update(['classification' => 'legit']);
        $this->traffic($this->domain, '209.85.220.41', $this->daysAgo(0), 10, 90);

        $this->evaluator()->legitFailing($this->domain);
        $alert = Alert::sole();
        $this->assertSame('critical', $alert->severity);

        $this->traffic($this->domain, '209.85.220.41', $this->daysAgo(-1), 500);
        $this->traffic($this->domain, '209.85.220.41', $this->daysAgo(-2), 500);
        $this->evaluator()->legitFailing($this->domain);
        $this->assertNotNull($alert->fresh()->resolved_at);
    }

    public function test_no_reports_raises_and_import_clears(): void
    {
        $this->domain->update(['last_report_at' => now()->subHours(80)]);
        $this->evaluator()->noReports($this->domain);
        $alert = Alert::sole();
        $this->assertSame('no_reports', $alert->type);

        $this->evaluator()->afterImport($this->domain);
        $this->assertSame('auto', $alert->fresh()->resolution);
    }

    public function test_tenant_can_disable_or_tune_alerts_over_msp_defaults(): void
    {
        $this->domain->update(['last_report_at' => now()->subHours(80)]);
        AlertRule::create(['tenant_id' => null, 'type' => 'no_reports', 'enabled' => true, 'thresholds' => ['hours' => 96]]);
        $this->evaluator()->noReports($this->domain);
        $this->assertSame(0, Alert::count(), 'MSP default of 96h applies');

        AlertRule::create(['tenant_id' => $this->domain->tenant_id, 'type' => 'no_reports', 'enabled' => true, 'thresholds' => ['hours' => 48]]);
        app(AlertSettings::class)->forget();
        $this->evaluator()->noReports($this->domain);
        $this->assertSame(1, Alert::count(), 'tenant override of 48h applies');

        AlertRule::where('tenant_id', $this->domain->tenant_id)->update(['enabled' => false]);
        app(AlertSettings::class)->forget();
        Alert::query()->delete();
        $this->evaluator()->noReports($this->domain);
        $this->assertSame(0, Alert::count(), 'disabled by tenant');
    }

    public function test_dmarc_record_removed_after_verification(): void
    {
        $dns = new ArrayResolver;
        $this->app->instance(Resolver::class, $dns);
        $dns->set('_dmarc.acme.test', 'TXT', ['v=DMARC1; p=quarantine; rua=mailto:'.$this->domain->ruaAddress()]);
        app(DomainScanner::class)->verify($this->domain);
        $this->assertSame(0, Alert::count(), 'first verification is not a change');

        $dns->set('_dmarc.acme.test', 'TXT', []);
        app(DomainScanner::class)->verify($this->domain->fresh());
        $alert = Alert::sole();
        $this->assertSame('critical', $alert->severity);
        $this->assertStringContainsString('removed', $alert->title);

        $dns->set('_dmarc.acme.test', 'TXT', ['v=DMARC1; p=quarantine; rua=mailto:'.$this->domain->ruaAddress()]);
        app(DomainScanner::class)->verify($this->domain->fresh());
        $this->assertSame('auto', $alert->fresh()->resolution);
    }

    public function test_policy_weakened_and_lookup_failure_is_ignored(): void
    {
        $dns = new ArrayResolver;
        $this->app->instance(Resolver::class, $dns);
        $rua = 'rua=mailto:'.$this->domain->ruaAddress();
        $dns->set('_dmarc.acme.test', 'TXT', ["v=DMARC1; p=reject; {$rua}"]);
        app(DomainScanner::class)->verify($this->domain);

        $dns->zone['_dmarc.acme.test'] = 'SERVFAIL';
        app(DomainScanner::class)->verify($this->domain->fresh());
        $this->assertSame(0, Alert::count(), 'a failed lookup is not a change');

        $dns->zone['_dmarc.acme.test'] = ['TXT' => ["v=DMARC1; p=none; {$rua}"]];
        app(DomainScanner::class)->verify($this->domain->fresh());
        $alert = Alert::sole();
        $this->assertSame('warning', $alert->severity);
        $this->assertStringContainsString('p=reject to p=none', $alert->body);
        $this->assertNotNull($this->domain->fresh()->policy_changed_at);
    }

    public function test_no_dns_alerts_during_onboarding(): void
    {
        $dns = new ArrayResolver;
        $this->app->instance(Resolver::class, $dns);
        $dns->set('acme.test', 'TXT', ['v=spf1 +all']);
        app(DomainScanner::class)->scan($this->domain);
        $this->assertSame(0, Alert::count());
    }

    public function test_spf_problem_after_setup(): void
    {
        $dns = new ArrayResolver;
        $this->app->instance(Resolver::class, $dns);
        $dns->set('_dmarc.acme.test', 'TXT', ['v=DMARC1; p=none; rua=mailto:'.$this->domain->ruaAddress()]);
        $dns->set('acme.test', 'TXT', ['v=spf1 include:gone.example -all']);
        app(DomainScanner::class)->verify($this->domain);
        app(DomainScanner::class)->scan($this->domain->fresh());
        $alert = Alert::where('type', 'spf_problem')->sole();
        $this->assertStringContainsString('gone.example', $alert->body);

        $dns->set('acme.test', 'TXT', ['v=spf1 ip4:192.0.2.1 -all']);
        app(DomainScanner::class)->scan($this->domain->fresh());
        $this->assertNotNull($alert->fresh()->resolved_at);
    }

    public function test_ingest_failures_raise_msp_alert(): void
    {
        InboundMessage::create(['spool_id' => 'x', 'rua_token' => 'aaaaaaaaaa', 'raw_path' => 'x', 'size' => 1, 'status' => 'failed', 'received_at' => now()]);
        $this->evaluator()->ingestFailures();
        $alert = Alert::sole();
        $this->assertNull($alert->tenant_id);
        $this->assertSame('ingest_failures', $alert->type);

        $this->travel(2)->hours();
        $this->evaluator()->ingestFailures();
        $this->assertNotNull($alert->fresh()->resolved_at);
    }

    public function test_hourly_command_runs(): void
    {
        $this->domain->update(['last_report_at' => now()->subDays(5)]);
        $this->artisan('dmarc:evaluate-alerts')->assertSuccessful();
        $this->assertSame(AlertType::NoReports->value, Alert::sole()->type);
    }

    public function test_manager_resolve_reopens_on_new_detection(): void
    {
        $this->domain->update(['last_report_at' => now()->subHours(80)]);
        $this->evaluator()->noReports($this->domain);
        app(AlertManager::class)->resolve(Alert::sole(), 'manual');
        $this->evaluator()->noReports($this->domain);
        $this->assertSame(2, Alert::count());
        $this->assertSame(1, Alert::open()->count());
    }
}
