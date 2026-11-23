<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Reports\ShareLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** Regression tests for the findings from the PR security review. */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['slug' => 'acme']);
        $this->domain = Domain::factory()->for($this->tenant)->create(['name' => 'acme.test']);
    }

    private function superAdmin(bool $withTotp): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();
        if ($withTotp) {
            $u->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP');
        }

        return $u;
    }

    public function test_super_admin_without_two_factor_gets_no_tenant_access_anywhere(): void
    {
        $admin = $this->superAdmin(withTotp: false);
        $this->actingAs($admin);

        $this->get('/app/acme')->assertRedirectContains('multi-factor-authentication');
        $this->get(route('reports.domain', $this->domain))->assertForbidden();
        $this->get(route('reports.tenant', $this->tenant))->assertForbidden();
        $this->get('/horizon')->assertForbidden();
    }

    public function test_super_admin_with_two_factor_has_access(): void
    {
        $this->actingAs($this->superAdmin(withTotp: true));
        $this->get('/app/acme')->assertOk();
        $this->get(route('reports.domain', $this->domain))->assertOk();
    }

    public function test_reports_honour_tenant_two_factor_requirement(): void
    {
        $this->tenant->update(['require_mfa' => true]);
        $user = User::factory()->create();
        Membership::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'role' => 'viewer']);

        $this->actingAs($user)->get(route('reports.domain', $this->domain))->assertForbidden();
        $this->actingAs($user)->get(route('reports.tenant', $this->tenant))->assertForbidden();

        $this->actingAs($user)->withSession(['auth_via_sso' => 'google'])->get(route('reports.domain', $this->domain))->assertOk();

        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP');
        $this->actingAs($user)->withSession([])->get(route('reports.domain', $this->domain))->assertOk();
    }

    public function test_links_use_https_behind_the_tls_proxy(): void
    {
        config(['app.url' => 'https://dmarc.msp.test']);
        URL::forceScheme('https');
        URL::forceRootUrl('https://dmarc.msp.test');

        $link = ShareLinks::domain($this->domain, 30);
        $this->assertStringStartsWith('https://dmarc.msp.test/shared/reports/', $link);

        // Caddy forwards plain HTTP with X-Forwarded-Proto: https; the signature must still validate.
        $path = substr($link, strlen('https://dmarc.msp.test'));
        $this->get('http://dmarc.msp.test'.$path, ['X-Forwarded-Proto' => 'https'])->assertOk();
        // Without the proxy header the scheme differs, so the signature doesn't match.
        $this->get('http://dmarc.msp.test'.$path)->assertForbidden();
    }

    public function test_forwarded_host_is_not_trusted(): void
    {
        $this->get('http://dmarc.msp.test/up', ['X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Proto' => 'https'])->assertOk();
        $this->assertSame('dmarc.msp.test', request()->getHost());
    }

    public function test_responses_carry_security_headers(): void
    {
        $r = $this->get('/app/login')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString("frame-ancestors 'none'", $r->headers->get('Content-Security-Policy'));
        $r->assertHeaderMissing('Strict-Transport-Security');

        // Behind Caddy the request is HTTPS: HSTS is sent.
        $this->get('/app/login', ['X-Forwarded-Proto' => 'https'])->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
