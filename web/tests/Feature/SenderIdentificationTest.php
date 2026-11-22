<?php

namespace Tests\Feature;

use App\Dns\ArrayResolver;
use App\Dns\Resolver;
use App\Dns\ReverseName;
use App\Intel\AsnDatabase;
use App\Intel\IpEnricher;
use App\Intel\MmdbAsnDatabase;
use App\Intel\SenderIdentifier;
use App\Intel\SourceRegistry;
use App\Jobs\EnrichSourceIps;
use App\Models\DailySourceStat;
use App\Models\Domain;
use App\Models\DomainSource;
use App\Models\IpIntel;
use App\Models\KnownSender;
use App\Models\Report;
use App\Services\StatsAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SenderIdentificationTest extends TestCase
{
    use RefreshDatabase;

    private ArrayResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dns = new ArrayResolver;
        $this->app->instance(Resolver::class, $this->dns);
        $this->app->instance(AsnDatabase::class, new MmdbAsnDatabase(base_path('tests/Fixtures/ipdb/ipinfo-lite-test.mmdb')));
    }

    private function ptr(string $ip, string $host, array $forward): void
    {
        $this->dns->set(ReverseName::for($ip), 'PTR', $host);
        $this->dns->set($host, 'A', $forward);
    }

    private function record(Domain $d, string $ip, array $spf = [], array $dkim = [], int $count = 10, bool $pass = true): void
    {
        $report = Report::create([
            'domain_id' => $d->id, 'reporter_org' => 'google.com', 'report_id' => uniqid(), 'policy_domain' => $d->name,
            'date_begin' => '2026-11-01', 'date_end' => '2026-11-01', 'policy_published' => [], 'message_count' => $count,
        ]);
        DB::table('report_records')->insert([
            'report_id' => $report->id, 'domain_id' => $d->id, 'day' => '2026-11-01', 'source_ip' => $ip, 'count' => $count,
            'disposition' => 'none', 'dkim_aligned' => $pass, 'spf_aligned' => false, 'header_from' => $d->name,
            'spf_results' => json_encode($spf), 'dkim_results' => json_encode($dkim),
        ]);
        app(StatsAggregator::class)->refreshDay($d->id, '2026-11-01');
    }

    public function test_known_senders_are_seeded(): void
    {
        $this->assertGreaterThanOrEqual(30, KnownSender::count());
        $this->assertSame([15169], KnownSender::firstWhere('name', 'Google Workspace')->asns);
    }

    public function test_forward_confirmed_rdns_identifies_sender(): void
    {
        $this->ptr('198.51.100.5', 'o1.ptr1234.sendgrid.net', ['198.51.100.5']);
        $intel = app(IpEnricher::class)->enrich('198.51.100.5');
        $this->assertTrue($intel->rdns_verified);
        $this->assertSame('SendGrid', $intel->knownSender->name);
        $this->assertSame('rdns', $intel->match_reason);
    }

    public function test_spoofed_rdns_is_not_trusted(): void
    {
        // Attacker sets their PTR to a Google name, but Google's name doesn't resolve back to them.
        $this->ptr('203.0.113.66', 'mail-sor-f41.google.com', ['209.85.220.41']);
        $intel = app(IpEnricher::class)->enrich('203.0.113.66');
        $this->assertSame('mail-sor-f41.google.com', $intel->rdns);
        $this->assertFalse($intel->rdns_verified);
        $this->assertNull($intel->known_sender_id);
    }

    public function test_report_contents_never_identify_an_ip(): void
    {
        // Anyone who knows a published rua address can send a forged report claiming SPF/DKIM
        // passes for a provider. That must not relabel the IP for every tenant.
        $victim = Domain::factory()->create(['name' => 'victim.test']);
        $attacker = Domain::factory()->create(['name' => 'attacker.test']);
        $this->record($victim, '203.0.113.20');
        DomainSource::where('domain_id', $victim->id)->update(['classification' => 'unknown']);
        $this->record($attacker, '203.0.113.20',
            spf: [['domain' => 'bounces.sendgrid.net', 'result' => 'pass']],
            dkim: [['domain' => 'sendgrid.net', 'selector' => 's1', 'result' => 'pass']]);

        EnrichSourceIps::dispatchSync(['203.0.113.20'], force: true);

        $this->assertNull(IpIntel::firstWhere('ip', '203.0.113.20')->known_sender_id);
        $this->assertSame('ip:203.0.113.20', DailySourceStat::where('domain_id', $victim->id)->value('source_key'));
    }

    public function test_dedicated_asn_identifies_sender(): void
    {
        $intel = app(IpEnricher::class)->enrich('209.85.220.41');
        $this->assertSame(15169, $intel->asn);
        $this->assertSame('Google Workspace', $intel->knownSender->name);
        $this->assertSame('asn', $intel->match_reason);
    }

    public function test_suffix_matching_is_label_aligned(): void
    {
        $this->assertTrue(SenderIdentifier::matchesAny('em1.sendgrid.net', ['sendgrid.net']));
        $this->assertTrue(SenderIdentifier::matchesAny('sendgrid.net', ['sendgrid.net']));
        $this->assertFalse(SenderIdentifier::matchesAny('evilsendgrid.net', ['sendgrid.net']));
    }

    public function test_enrichment_rekeys_stats_and_keeps_classification(): void
    {
        $d = Domain::factory()->create(['name' => 'example.com']);
        $this->record($d, '198.51.100.5');
        app(SourceRegistry::class)->sync($d->id);

        $this->assertSame('ip:198.51.100.5', DailySourceStat::sole()->source_key);
        // The user classified the bare IP before we identified it.
        DomainSource::where('source_key', 'ip:198.51.100.5')->update(['classification' => 'legit']);

        $this->ptr('198.51.100.5', 'o1.ptr1234.sendgrid.net', ['198.51.100.5']);
        EnrichSourceIps::dispatchSync(['198.51.100.5']);

        $sendgrid = KnownSender::firstWhere('name', 'SendGrid');
        $this->assertSame('sender:'.$sendgrid->id, DailySourceStat::sole()->source_key);
        $src = DomainSource::where('source_key', 'sender:'.$sendgrid->id)->sole();
        $this->assertSame('SendGrid', $src->label);
        $this->assertSame('legit', $src->classification);
    }

    public function test_ingest_registers_sources_and_enriches(): void
    {
        $d = Domain::factory()->create(['name' => 'example.com']);
        $this->record($d, '209.85.220.41');
        $ips = ['209.85.220.41'];
        app(SourceRegistry::class)->sync($d->id);
        EnrichSourceIps::dispatchSync($ips);

        $this->assertNotNull(IpIntel::firstWhere('ip', '209.85.220.41'));
        $google = KnownSender::firstWhere('name', 'Google Workspace');
        $this->assertSame('Google Workspace', DomainSource::where('domain_id', $d->id)->where('source_key', 'sender:'.$google->id)->value('label'));
        $this->assertSame('sender:'.$google->id, DailySourceStat::sole()->source_key);
    }
}
