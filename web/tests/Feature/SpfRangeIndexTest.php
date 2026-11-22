<?php

namespace Tests\Feature;

use App\Dns\ArrayResolver;
use App\Dns\Resolver;
use App\Dns\ReverseName;
use App\Intel\IpEnricher;
use App\Intel\SpfRangeIndex;
use App\Models\KnownSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpfRangeIndexTest extends TestCase
{
    use RefreshDatabase;

    private ArrayResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dns = new ArrayResolver;
        $this->app->instance(Resolver::class, $this->dns);
    }

    private function spf(string $domain, string $record): void
    {
        $this->dns->set($domain, 'TXT', ['some-verification=abc', $record]);
    }

    public function test_collects_ranges_with_depth(): void
    {
        $this->spf('esp.example', 'v=spf1 ip4:198.51.100.0/24 ip6:2001:db8:5::/48 include:_more.esp.example a:out.esp.example/28 mx -ip4:198.51.100.7 ~all');
        $this->spf('_more.esp.example', 'v=spf1 ip4:203.0.113.0/25 redirect=_last.esp.example');
        $this->spf('_last.esp.example', 'v=spf1 ip4:192.0.2.128/25 -all');
        $this->dns->set('out.esp.example', 'A', ['192.0.2.64'])->set('esp.example', 'MX', ['mx.esp.example'])->set('mx.esp.example', 'A', ['192.0.2.10']);

        $ranges = app(SpfRangeIndex::class)->rangesFor('esp.example');
        $this->assertEqualsCanonicalizing([
            ['198.51.100.0/24', 1], ['2001:db8:5::/48', 1], ['203.0.113.0/25', 2], ['192.0.2.128/25', 2],
            ['192.0.2.64/28', 1], ['192.0.2.10', 1],
        ], $ranges);
    }

    public function test_rejects_broad_ranges_loops_and_macros(): void
    {
        $this->spf('bad.example', 'v=spf1 ip4:0.0.0.0/1 ip6:2000::/3 include:bad.example exists:%{i}.x.example include:%{d}.y.example ip4:198.51.100.1 ?all');
        $this->assertSame([['198.51.100.1', 1]], app(SpfRangeIndex::class)->rangesFor('bad.example'));
    }

    public function test_ranges_are_cached_and_failures_are_not_matches(): void
    {
        $this->spf('esp.example', 'v=spf1 ip4:198.51.100.0/24 -all');
        $index = app(SpfRangeIndex::class);
        $index->rangesFor('esp.example');
        $index->rangesFor('esp.example');
        $this->assertSame(1, $this->dns->queries['esp.example']);

        $this->dns->zone['down.example'] = 'SERVFAIL';
        $this->assertSame([], $index->rangesFor('down.example'));
        $this->assertNull($index->bestMatch('198.51.100.5', ['down.example']));
    }

    public function test_identifies_sender_from_published_ranges(): void
    {
        $sendgrid = KnownSender::firstWhere('name', 'SendGrid');
        $this->spf('sendgrid.net', 'v=spf1 ip4:149.72.0.0/16 ip6:2001:db8:aa::/48 ~all');

        $intel = app(IpEnricher::class)->enrich('149.72.154.232');
        $this->assertSame($sendgrid->id, $intel->known_sender_id);
        $this->assertSame('spf', $intel->match_reason);
        $this->assertSame($sendgrid->id, app(IpEnricher::class)->enrich('2001:db8:aa::25')->known_sender_id);
        $this->assertNull(app(IpEnricher::class)->enrich('149.73.0.1')->known_sender_id);
    }

    public function test_provider_beats_a_company_that_includes_it(): void
    {
        // A catalogue entry whose include is a company domain that itself includes Google's record.
        KnownSender::create(['name' => 'Some CRM', 'spf_includes' => ['crm.example']]);
        $this->spf('crm.example', 'v=spf1 include:_spf.google.com ip4:192.0.2.0/24 -all');
        $this->spf('_spf.google.com', 'v=spf1 include:_netblocks.google.com ~all');
        $this->spf('_netblocks.google.com', 'v=spf1 ip4:209.85.128.0/17 ~all');

        $this->assertSame('Google Workspace', app(IpEnricher::class)->enrich('209.85.220.41')->knownSender->name);
        $this->assertSame('Some CRM', app(IpEnricher::class)->enrich('192.0.2.9')->knownSender->name);
    }

    public function test_more_specific_range_wins_and_exact_ties_are_not_guessed(): void
    {
        KnownSender::create(['name' => 'Wide', 'spf_includes' => ['wide.example']]);
        KnownSender::create(['name' => 'Narrow', 'spf_includes' => ['narrow.example']]);
        KnownSender::create(['name' => 'Twin', 'spf_includes' => ['twin.example']]);
        $this->spf('wide.example', 'v=spf1 ip4:198.18.0.0/16 -all');
        $this->spf('narrow.example', 'v=spf1 ip4:198.18.5.0/24 -all');
        $this->spf('twin.example', 'v=spf1 ip4:198.18.0.0/16 -all');

        $this->assertSame('Narrow', app(IpEnricher::class)->enrich('198.18.5.9')->knownSender->name);
        $this->assertNull(app(IpEnricher::class)->enrich('198.18.9.9')->known_sender_id, 'Wide and Twin tie: no guess');
    }

    public function test_verified_rdns_outranks_spf_ranges(): void
    {
        $this->spf('sendgrid.net', 'v=spf1 ip4:198.51.100.0/24 ~all');
        $this->dns->set(ReverseName::for('198.51.100.5'), 'PTR', 'mail.icloud.com')->set('mail.icloud.com', 'A', ['198.51.100.5']);
        $intel = app(IpEnricher::class)->enrich('198.51.100.5');
        $this->assertSame('iCloud Mail', $intel->knownSender->name);
        $this->assertSame('rdns', $intel->match_reason);
    }
}
