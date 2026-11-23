<?php

namespace Tests\Feature;

use App\AccountRequests\AccountRequestDeclinedMail;
use App\AccountRequests\NewAccountRequestMail;
use App\Filament\Admin\Resources\AccountRequests\Pages\ListAccountRequests;
use App\Filament\Admin\Resources\AccountRequests\Pages\ViewAccountRequest;
use App\Models\AccountRequest;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Tenant;
use App\Models\User;
use App\Team\InvitationMail;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    private function enable(): void
    {
        config(['dmarc.public_site.enabled' => true]);
    }

    private function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    /** @return array<string, mixed> */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Pat Smith',
            'email' => 'Pat@Example.org',
            'organisation' => 'Example Charity',
            'domains' => "example.org\nexample.net",
            'message' => 'Thanks!',
            'volume' => '1',
        ], $overrides);
    }

    private function pending(): AccountRequest
    {
        $this->enable();
        Mail::fake();
        $this->post('/request-account', $this->form());

        return AccountRequest::sole();
    }

    public function test_disabled_by_default(): void
    {
        $this->get('/')->assertRedirect('/app');
        $this->get('/request-account')->assertNotFound();
        $this->post('/request-account', $this->form())->assertNotFound();
        $this->assertSame(0, AccountRequest::count());
    }

    public function test_home_page_explains_the_service_without_signing_in(): void
    {
        $this->enable();
        $this->get('/')
            ->assertOk()
            ->assertSee('Email security basics should be easy')
            ->assertSee('Request a free account')
            ->assertSee('within 48 hours')
            ->assertSee('up to 10,000 emails a month')
            ->assertSee('include tech support', false)
            ->assertSee('https://github.com/alephcom/dmarc-processor', false);
        $this->get('/request-account')->assertOk()->assertSee('Domains to protect');
    }

    public function test_submitting_a_request_notifies_super_admins(): void
    {
        $this->enable();
        Mail::fake();
        $admin = $this->superAdmin();

        $this->post('/request-account', $this->form())->assertRedirect('/request-account/thanks');
        $this->get('/request-account/thanks')->assertOk()->assertSee('within 48 hours');

        $req = AccountRequest::sole();
        $this->assertSame('pat@example.org', $req->email);
        $this->assertSame(AccountRequest::PENDING, $req->status);
        Mail::assertQueued(NewAccountRequestMail::class, fn ($m) => $m->hasTo($admin->email));
        $this->assertSame(1, $admin->notifications()->count());
    }

    public function test_requires_volume_confirmation_and_valid_fields(): void
    {
        $this->enable();
        $this->post('/request-account', $this->form(['volume' => null, 'email' => 'nope']))
            ->assertSessionHasErrors(['volume', 'email']);
        $this->assertSame(0, AccountRequest::count());
    }

    public function test_honeypot_and_duplicates_do_not_create_requests(): void
    {
        $this->enable();
        Mail::fake();
        $this->post('/request-account', $this->form(['website' => 'http://spam.test']))->assertRedirect('/request-account/thanks');
        $this->assertSame(0, AccountRequest::count());

        $this->post('/request-account', $this->form());
        $this->post('/request-account', $this->form(['email' => 'pat@example.org']));
        $this->assertSame(1, AccountRequest::count());
    }

    private function enableTurnstile(): void
    {
        config(['services.turnstile.site_key' => 'site-key', 'services.turnstile.secret_key' => 'secret-key']);
    }

    public function test_turnstile_widget_is_shown_only_when_configured(): void
    {
        $this->enable();
        $this->get('/request-account')->assertDontSee('challenges.cloudflare.com', false);

        $this->enableTurnstile();
        $this->get('/request-account')
            ->assertSee('challenges.cloudflare.com/turnstile/v0/api.js', false)
            ->assertSee('data-sitekey="site-key"', false);
    }

    public function test_turnstile_token_is_verified_with_cloudflare(): void
    {
        $this->enable();
        $this->enableTurnstile();
        Mail::fake();
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true, 'action' => 'account-request'])]);

        $this->post('/request-account', $this->form(['cf-turnstile-response' => 'good-token']))->assertRedirect('/request-account/thanks');

        $this->assertSame(1, AccountRequest::count());
        Http::assertSent(fn (HttpRequest $r) => $r['secret'] === 'secret-key' && $r['response'] === 'good-token');
    }

    public function test_failed_or_missing_turnstile_is_rejected(): void
    {
        $this->enable();
        $this->enableTurnstile();
        Http::fake(['challenges.cloudflare.com/*' => Http::sequence()
            ->push(['success' => false, 'error-codes' => ['invalid-input-response']])
            ->push(['success' => true, 'action' => 'some-other-form'])
            ->push('', 500)]);

        $this->post('/request-account', $this->form())->assertSessionHasErrors('turnstile');
        foreach (['bad', 'wrong-action', 'cloudflare-down'] as $token) {
            $this->post('/request-account', $this->form(['cf-turnstile-response' => $token]))->assertSessionHasErrors('turnstile');
        }
        $this->assertSame(0, AccountRequest::count());
        Http::assertSentCount(3); // a missing token never reaches Cloudflare
    }

    public function test_submissions_are_rate_limited(): void
    {
        $this->enable();
        Mail::fake();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/request-account', $this->form(['email' => "p{$i}@example.org"]));
        }
        $this->post('/request-account', $this->form(['email' => 'p9@example.org']))->assertTooManyRequests();
    }

    public function test_approving_creates_organisation_and_invites_requester_as_owner(): void
    {
        $req = $this->pending();
        Tenant::factory()->create(['slug' => 'example-charity']);
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListAccountRequests::class)->assertCanSeeTableRecords([$req]);
        Livewire::test(ViewAccountRequest::class, ['record' => $req->getRouteKey()])
            ->callAction('approve')
            ->assertHasNoActionErrors();

        $req->refresh();
        $tenant = Tenant::firstWhere('name', 'Example Charity');
        $this->assertSame(AccountRequest::APPROVED, $req->status);
        $this->assertSame($tenant->id, $req->tenant_id);
        $this->assertSame($admin->id, $req->reviewed_by);
        $this->assertSame('example-charity-2', $tenant->slug);
        $inv = Invitation::sole();
        $this->assertSame(['pat@example.org', 'owner', $tenant->id], [$inv->email, $inv->role, $inv->tenant_id]);
        Mail::assertSent(InvitationMail::class, fn ($m) => $m->hasTo('pat@example.org') && str_contains($m->render(), 'include tech support'));
        $this->assertTrue(AuditLog::where('action', 'account_request.approved')->exists());

        // Already reviewed: no second organisation.
        Livewire::test(ViewAccountRequest::class, ['record' => $req->getRouteKey()])->assertActionHidden('approve');
        $this->assertSame(2, Tenant::count());
    }

    public function test_declining_can_email_the_requester(): void
    {
        $req = $this->pending();
        $this->actingAs($this->superAdmin());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListAccountRequests::class)
            ->callTableAction('reject', $req, ['notify' => true, 'note' => 'Too large for us.'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(AccountRequest::REJECTED, $req->fresh()->status);
        $this->assertSame(0, Tenant::count());
        Mail::assertSent(AccountRequestDeclinedMail::class, fn ($m) => $m->hasTo('pat@example.org') && $m->note === 'Too large for us.');
    }

    public function test_tenant_users_cannot_review_requests(): void
    {
        $req = $this->pending();
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner']);

        $this->actingAs($owner)->get('/admin/account-requests')->assertForbidden();
        $this->actingAs($owner)->get("/admin/account-requests/{$req->id}")->assertForbidden();
    }
}
