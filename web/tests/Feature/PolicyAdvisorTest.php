<?php

namespace Tests\Feature;

use App\Models\DnsCheck;
use App\Models\Domain;
use App\Models\DomainSource;
use App\Services\PolicyAdvisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsTraffic;
use Tests\TestCase;

class PolicyAdvisorTest extends TestCase
{
    use BuildsTraffic, RefreshDatabase;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = Domain::factory()->create([
            'name' => 'acme.test', 'status' => 'active', 'current_policy' => 'none', 'current_pct' => 100,
            'dns_verified_at' => now()->subDays(40), 'first_report_at' => now()->subDays(40), 'policy_changed_at' => now()->subDays(40),
        ]);
        DnsCheck::create(['domain_id' => $this->domain->id, 'type' => 'dmarc', 'status' => 'ok', 'checked_at' => now(),
            'raw' => 'v=DMARC1; p=none; rua=mailto:'.$this->domain->ruaAddress().'; adkim=s']);
    }

    private function healthyTraffic(): void
    {
        foreach (range(13, 0) as $n) {
            $this->traffic($this->domain, '209.85.220.41', $this->daysAgo($n), 1000);
        }
        DomainSource::query()->update(['classification' => 'legit']);
    }

    private function advise()
    {
        return app(PolicyAdvisor::class)->advise($this->domain->fresh());
    }

    public function test_requires_setup(): void
    {
        $this->domain->forceFill(['dns_verified_at' => null])->save();
        $this->assertSame('setup', $this->advise()->status);
    }

    public function test_ready_for_quarantine_with_exact_record(): void
    {
        $this->healthyTraffic();
        $a = $this->advise();
        $this->assertSame('ready', $a->status, json_encode($a->checks));
        $this->assertSame(['quarantine', 25], $a->next);
        $this->assertSame('v=DMARC1; p=quarantine; pct=25; rua=mailto:'.$this->domain->ruaAddress().'; adkim=s', $a->record);
    }

    public function test_blocked_until_legit_senders_are_marked(): void
    {
        foreach (range(13, 0) as $n) {
            $this->traffic($this->domain, '209.85.220.41', $this->daysAgo($n), 1000);
        }
        $a = $this->advise();
        $this->assertSame('blocked', $a->status);
        $this->assertStringContainsString('No senders marked legitimate', collect($a->checks)->firstWhere(0, false)[1]);
        $this->assertNull($a->record);
    }

    public function test_blocked_when_legit_mail_fails(): void
    {
        $this->healthyTraffic();
        $this->traffic($this->domain, '209.85.220.41', $this->daysAgo(0), 0, 2000);
        $this->assertSame('blocked', $this->advise()->status);
    }

    public function test_blocked_when_unknown_share_is_high(): void
    {
        $this->healthyTraffic();
        $this->traffic($this->domain, '203.0.113.9', $this->daysAgo(0), 0, 500); // unknown, 3.4% of mail
        $a = $this->advise();
        $this->assertSame('blocked', $a->status);
        $this->assertStringContainsString('not yet marked', collect($a->checks)->firstWhere(0, false)[1]);

        DomainSource::where('source_key', 'ip:203.0.113.9')->update(['classification' => 'suspicious']);
        $this->assertSame('ready', $this->advise()->status, 'marking the spoofer suspicious unblocks it');
    }

    public function test_waits_for_enough_history(): void
    {
        $this->domain->forceFill(['first_report_at' => now()->subDays(10)])->save();
        $this->healthyTraffic();
        $a = $this->advise();
        $this->assertSame('wait', $a->status);
        $this->assertStringContainsString('10 of 30 days', $a->checks[0][1]);
    }

    public function test_steps_through_quarantine_percentages_to_reject(): void
    {
        $this->healthyTraffic();
        $this->domain->forceFill(['current_policy' => 'quarantine', 'current_pct' => 25, 'policy_changed_at' => now()->subDays(5)])->save();
        $this->assertSame('wait', $this->advise()->status);
        $this->domain->forceFill(['policy_changed_at' => now()->subDays(15)])->save();
        $this->assertSame(['quarantine', 50], $this->advise()->next);

        $this->domain->forceFill(['current_pct' => 100, 'policy_changed_at' => now()->subDays(20)])->save();
        $a = $this->advise();
        $this->assertSame('wait', $a->status, 'reject needs 30 days at quarantine 100%');
        $this->assertSame(['reject', 100], $a->next);

        $this->domain->forceFill(['policy_changed_at' => now()->subDays(31)])->save();
        $this->assertSame('ready', $this->advise()->status);

        $this->domain->forceFill(['current_policy' => 'reject'])->save();
        $this->assertSame('done', $this->advise()->status);
    }

    public function test_quarantine_target_stops_there(): void
    {
        $this->domain->forceFill(['current_policy' => 'quarantine', 'current_pct' => 100, 'target_policy' => 'quarantine'])->save();
        $this->assertSame('done', $this->advise()->status);
    }
}
