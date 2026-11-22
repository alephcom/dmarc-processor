<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Sso\SsoIdentity;
use App\Sso\SsoLogin;
use App\Sso\SsoRejected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class SsoTest extends TestCase
{
    use RefreshDatabase;

    private const TID = '11111111-2222-3333-4444-555555555555';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create([
            'slug' => 'acme', 'sso_domains' => ['acme.com'], 'sso_microsoft_tenant_ids' => [self::TID], 'sso_default_role' => 'viewer',
        ]);
    }

    private function google(string $email = 'pat@acme.com', bool $verified = true, ?string $hd = 'acme.com', string $sub = 'g-123'): SsoIdentity
    {
        return new SsoIdentity('google', $sub, $email, 'Pat', emailVerified: $verified, hostedDomain: $hd);
    }

    private function microsoft(string $email = 'pat@acme.com', ?string $tid = self::TID, string $oid = 'm-123'): SsoIdentity
    {
        return new SsoIdentity('microsoft', $oid, $email, 'Pat', microsoftTenantId: $tid);
    }

    public function test_google_workspace_user_is_created_just_in_time(): void
    {
        $user = app(SsoLogin::class)->resolve($this->google());
        $this->assertSame('pat@acme.com', $user->email);
        $this->assertSame(['google', 'g-123'], [$user->sso_provider, $user->sso_id]);
        $this->assertSame('viewer', Membership::where('user_id', $user->id)->value('role'));
        $this->assertTrue(AuditLog::where('action', 'user.sso_created')->exists());

        // Second sign-in finds the same user by subject ID.
        $this->assertTrue($user->is(app(SsoLogin::class)->resolve($this->google())));
        $this->assertSame(1, User::count());
    }

    public function test_google_rejects_unverified_consumer_or_mismatched_domain(): void
    {
        foreach ([
            'unverified email' => $this->google(verified: false),
            'consumer gmail account claiming the domain' => $this->google(hd: null),
            'hosted domain differs from email' => $this->google(hd: 'evil.com'),
            'domain not configured' => $this->google('pat@other.com', hd: 'other.com'),
        ] as $case => $identity) {
            try {
                app(SsoLogin::class)->resolve($identity);
                $this->fail("accepted: {$case}");
            } catch (SsoRejected) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, User::count());
    }

    public function test_microsoft_requires_allow_listed_directory(): void
    {
        $this->assertThrows(fn () => app(SsoLogin::class)->resolve($this->microsoft(tid: '99999999-0000-0000-0000-000000000000')), SsoRejected::class);
        $this->assertThrows(fn () => app(SsoLogin::class)->resolve($this->microsoft(tid: null)), SsoRejected::class);

        $user = app(SsoLogin::class)->resolve($this->microsoft());
        $this->assertSame('microsoft', $user->sso_provider);
    }

    public function test_links_existing_account_but_never_takes_over_super_admins(): void
    {
        $existing = User::factory()->create(['email' => 'pat@acme.com']);
        Membership::create(['tenant_id' => $this->tenant->id, 'user_id' => $existing->id, 'role' => 'admin']);
        $this->assertTrue($existing->is(app(SsoLogin::class)->resolve($this->google())));
        $this->assertSame('admin', Membership::where('user_id', $existing->id)->value('role'), 'existing role kept');

        $admin = User::factory()->create(['email' => 'ops@acme.com']);
        $admin->forceFill(['is_super_admin' => true])->save();
        $this->assertThrows(fn () => app(SsoLogin::class)->resolve($this->google('ops@acme.com', sub: 'g-ops')), SsoRejected::class, 'two-factor');
    }

    public function test_account_linked_to_other_identity_is_not_relinked(): void
    {
        User::factory()->create(['email' => 'pat@acme.com'])->forceFill(['sso_provider' => 'microsoft', 'sso_id' => 'm-1'])->save();
        $this->assertThrows(fn () => app(SsoLogin::class)->resolve($this->google()), SsoRejected::class);
    }

    public function test_callback_signs_in_and_redirects(): void
    {
        config(['services.google.client_id' => 'cid', 'services.google.client_secret' => 'secret']);
        $su = (new SocialiteUser)->setRaw(['email_verified' => true, 'hd' => 'acme.com'])->map(['id' => 'g-9', 'email' => 'new@acme.com', 'name' => 'New']);
        $driver = Mockery::mock();
        $driver->shouldReceive('user')->andReturn($su);
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $this->get('/auth/sso/google/callback?code=x&state=y')->assertRedirect('/app');
        $this->assertAuthenticatedAs(User::firstWhere('email', 'new@acme.com'));
        $this->assertSame('google', session('auth_via_sso'));
    }

    public function test_rejected_callback_shows_message_on_login_page(): void
    {
        config(['services.google.client_id' => 'cid', 'services.google.client_secret' => 'secret']);
        $su = (new SocialiteUser)->setRaw(['email_verified' => true])->map(['id' => 'g-9', 'email' => 'someone@gmail.com', 'name' => 'X']);
        $driver = Mockery::mock();
        $driver->shouldReceive('user')->andReturn($su);
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $this->get('/auth/sso/google/callback')->assertRedirect('/app/login')->assertSessionHas('sso_error');
        $this->assertGuest();
    }

    public function test_disabled_provider_is_404_and_buttons_only_when_configured(): void
    {
        config(['services.microsoft.client_id' => null, 'services.google.client_id' => 'cid']);
        $this->get('/auth/sso/microsoft/redirect')->assertNotFound();
        $this->get('/app/login')->assertOk()->assertSee('Sign in with Google')->assertDontSee('Sign in with Microsoft');
        $this->get('/admin/login')->assertOk()->assertDontSee('Sign in with Google');
    }

    public function test_sso_users_skip_tenant_totp_requirement(): void
    {
        $this->tenant->update(['require_mfa' => true]);
        $user = app(SsoLogin::class)->resolve($this->google());
        $this->actingAs($user)->withSession(['auth_via_sso' => 'google'])->get('/app/acme')->assertOk();
        $this->actingAs($user)->withSession([])->flushSession()->get('/app/acme')->assertRedirect();
    }
}
