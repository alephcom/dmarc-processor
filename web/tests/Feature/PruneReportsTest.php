<?php

namespace Tests\Feature;

use App\Models\DailyDomainStat;
use App\Models\Domain;
use App\Models\InboundMessage;
use App\Models\Report;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PruneReportsTest extends TestCase
{
    use RefreshDatabase;

    private function report(Domain $d, string $end, string $id): Report
    {
        return Report::create([
            'domain_id' => $d->id, 'reporter_org' => 'google.com', 'report_id' => $id, 'policy_domain' => $d->name,
            'date_begin' => $end, 'date_end' => $end, 'policy_published' => ['p' => 'none'], 'message_count' => 1,
        ]);
    }

    public function test_prunes_per_tenant_retention_but_keeps_rollups(): void
    {
        Storage::fake('local');
        $short = Tenant::factory()->create(['retention_days_raw' => 30]);
        $long = Tenant::factory()->create(['retention_days_raw' => 400]);
        $d1 = Domain::factory()->for($short)->create();
        $d2 = Domain::factory()->for($long)->create();

        $this->report($d1, now()->subDays(60)->toDateTimeString(), 'old-1');
        $this->report($d1, now()->subDays(5)->toDateTimeString(), 'new-1');
        $this->report($d2, now()->subDays(60)->toDateTimeString(), 'old-2');
        DailyDomainStat::create(['domain_id' => $d1->id, 'day' => now()->subDays(60)->toDateString(), 'total' => 1]);

        Storage::disk('local')->put('inbound/old.eml.gz', 'x');
        InboundMessage::create([
            'spool_id' => 's1', 'domain_id' => $d1->id, 'rua_token' => $d1->rua_token, 'raw_path' => 'inbound/old.eml.gz',
            'size' => 1, 'status' => 'processed', 'received_at' => now()->subDays(60),
        ]);

        $this->artisan('dmarc:prune')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['new-1', 'old-2'], Report::pluck('report_id')->all());
        $this->assertSame(0, InboundMessage::count());
        Storage::disk('local')->assertMissing('inbound/old.eml.gz');
        $this->assertSame(1, DailyDomainStat::count());
    }
}
