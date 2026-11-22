<?php

namespace Tests\Feature;

use App\Dns\ArrayResolver;
use App\Dns\Resolver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://dmarc.msp.test',
            'dmarc.reports_domain' => 'reports.msp.test',
            'dmarc.mx_hostname' => 'mx.reports.msp.test',
            'dmarc.ingest_hmac_secret' => str_repeat('s', 40),
            'queue.default' => 'redis',
            'mail.default' => 'smtp',
        ]);
    }

    public function test_reports_missing_dns_and_admin(): void
    {
        $this->app->instance(Resolver::class, new ArrayResolver);
        $this->artisan('dmarc:install --check')
            ->expectsOutputToContain('Publish: reports.msp.test. MX 10 mx.reports.msp.test.')
            ->expectsOutputToContain('*._report._dmarc.reports.msp.test. TXT "v=DMARC1"')
            ->expectsOutputToContain('dmarc:make-super-admin')
            ->assertFailed();
    }

    public function test_passes_when_everything_is_in_place(): void
    {
        $dns = new class extends ArrayResolver
        {
            public function txt(string $name): array
            {
                // Simulates the wildcard record answering any label.
                return str_ends_with($name, '._report._dmarc.reports.msp.test') ? ['v=DMARC1'] : parent::txt($name);
            }
        };
        $dns->set('reports.msp.test', 'MX', ['mx.reports.msp.test'])
            ->set('mx.reports.msp.test', 'A', ['203.0.113.10'])
            ->set('dmarc.msp.test', 'A', ['203.0.113.10']);
        $this->app->instance(Resolver::class, $dns);
        User::factory()->create()->forceFill(['is_super_admin' => true])->save();

        $this->artisan('dmarc:install --check')->expectsOutputToContain('All required checks passed.')->assertSuccessful();
    }
}
