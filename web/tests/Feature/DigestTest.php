<?php

namespace Tests\Feature;

use App\Digests\MspDigestMail;
use App\Digests\TenantDigestMail;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Support\BuildsTraffic;
use Tests\TestCase;

class DigestTest extends TestCase
{
    use BuildsTraffic, RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->tenant = Tenant::factory()->create(['name' => 'Acme', 'digest_frequency' => 'weekly']);
        foreach (['owner' => 'owner@acme.test', 'admin' => 'admin@acme.test', 'viewer' => 'viewer@acme.test'] as $role => $email) {
            User::factory()->create(['email' => $email])->tenants()->attach($this->tenant, ['role' => $role]);
        }
        $admin = User::factory()->create(['email' => 'ops@msp.test']);
        $admin->forceFill(['is_super_admin' => true])->save();
    }

    public function test_weekly_digest_on_monday_to_managers_with_stats(): void
    {
        Carbon::setTestNow(Carbon::parse('next monday 07:13'));
        $d = Domain::factory()->for($this->tenant)->create(['name' => 'acme.test', 'dns_verified_at' => now()]);
        $this->traffic($d, '203.0.113.9', $this->daysAgo(1), 900, 100);

        $this->artisan('dmarc:send-digests')->assertSuccessful();

        Mail::assertSent(TenantDigestMail::class, function (TenantDigestMail $m) {
            $html = $m->render();

            return $m->hasTo('owner@acme.test') && $m->hasTo('admin@acme.test') && ! $m->hasTo('viewer@acme.test')
                && str_contains($html, 'acme.test') && str_contains($html, '90%') && str_contains($html, '1,000');
        });
        Mail::assertSent(MspDigestMail::class, fn ($m) => $m->hasTo('ops@msp.test') && str_contains($m->render(), 'Acme'));

        // Running again the same day doesn't double-send.
        $this->artisan('dmarc:send-digests');
        Mail::assertSent(TenantDigestMail::class, 1);
    }

    public function test_respects_frequency_and_day(): void
    {
        Carbon::setTestNow(Carbon::parse('next tuesday'));
        $this->artisan('dmarc:send-digests');
        Mail::assertNothingSent();

        $this->tenant->update(['digest_frequency' => 'monthly']);
        Carbon::setTestNow(Carbon::parse('first day of next month'));
        if (Carbon::now()->isMonday()) {
            Carbon::setTestNow(Carbon::now()->addMonth()->startOfMonth());
        }
        $this->artisan('dmarc:send-digests');
        Mail::assertSent(TenantDigestMail::class, 1);

        $this->tenant->update(['digest_frequency' => 'off', 'last_digest_at' => null]);
        Carbon::setTestNow(Carbon::parse('next monday'));
        $this->artisan('dmarc:send-digests');
        Mail::assertSent(TenantDigestMail::class, 1);
    }

    public function test_force_sends_now(): void
    {
        Carbon::setTestNow(Carbon::parse('next wednesday'));
        $this->artisan('dmarc:send-digests --force --tenant='.$this->tenant->slug)->assertSuccessful();
        Mail::assertSent(TenantDigestMail::class, 1);
        Mail::assertNotSent(MspDigestMail::class);
    }
}
