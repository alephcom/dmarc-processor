<?php

namespace Tests\Feature;

use App\Dns\ArrayResolver;
use App\Dns\Resolver;
use App\Models\DnsCheck;
use App\Models\Domain;
use App\Models\Report;
use App\Services\DomainScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DomainScannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_uses_selectors_seen_in_reports(): void
    {
        $dns = new ArrayResolver;
        $this->app->instance(Resolver::class, $dns);
        $d = Domain::factory()->create(['name' => 'example.com']);
        $report = Report::create([
            'domain_id' => $d->id, 'reporter_org' => 'google.com', 'report_id' => '1', 'policy_domain' => 'example.com',
            'date_begin' => now(), 'date_end' => now(), 'policy_published' => [], 'message_count' => 1,
        ]);
        DB::table('report_records')->insert([
            'report_id' => $report->id, 'domain_id' => $d->id, 'day' => now()->toDateString(), 'source_ip' => '192.0.2.1',
            'count' => 1, 'disposition' => 'none', 'dkim_aligned' => true, 'spf_aligned' => false, 'header_from' => 'example.com',
            'dkim_results' => json_encode([
                ['domain' => 'example.com', 'selector' => 'Seen2026', 'result' => 'pass'],
                ['domain' => 'other.net', 'selector' => 'notours', 'result' => 'pass'],
            ]),
        ]);

        $this->assertSame(['seen2026'], app(DomainScanner::class)->observedSelectors($d));
        app(DomainScanner::class)->scan($d);
        $this->assertArrayHasKey('seen2026._domainkey.example.com', $dns->queries);
        $this->assertArrayNotHasKey('notours._domainkey.example.com', $dns->queries);
    }

    public function test_lookup_failure_does_not_clear_verification(): void
    {
        $dns = new ArrayResolver;
        $this->app->instance(Resolver::class, $dns);
        $d = Domain::factory()->create(['name' => 'example.com']);
        $dns->set('_dmarc.example.com', 'TXT', ['v=DMARC1; p=none; rua=mailto:'.$d->ruaAddress()]);

        app(DomainScanner::class)->verify($d);
        $verifiedAt = $d->fresh()->dns_verified_at;
        $this->assertNotNull($verifiedAt);

        $dns->zone['_dmarc.example.com'] = 'SERVFAIL';
        app(DomainScanner::class)->verify($d->fresh());
        $this->assertEquals($verifiedAt, $d->fresh()->dns_verified_at);
        $this->assertSame('unknown', DnsCheck::where('type', 'dmarc')->latest('id')->first()->status);
    }

    public function test_check_dns_command(): void
    {
        $dns = new ArrayResolver;
        $this->app->instance(Resolver::class, $dns);
        $recent = Domain::factory()->create();
        $old = Domain::factory()->create(['created_at' => now()->subDays(5)]);
        Domain::factory()->create(['status' => Domain::STATUS_PAUSED]);

        $this->artisan('dmarc:check-dns --pending')->expectsOutput('Queued 1 DNS checks.')->assertSuccessful();
        $this->assertSame(['dmarc', 'rua_auth'], DnsCheck::where('domain_id', $recent->id)->orderBy('type')->pluck('type')->all());
        $this->assertSame(0, DnsCheck::where('domain_id', $old->id)->count());

        $this->artisan('dmarc:check-dns')->expectsOutput('Queued 2 DNS checks.')->assertSuccessful();
        $this->assertSame(4, DnsCheck::where('domain_id', $old->id)->count());
    }
}
