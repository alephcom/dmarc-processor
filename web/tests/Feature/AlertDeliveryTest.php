<?php

namespace Tests\Feature;

use App\Alerts\AlertManager;
use App\Alerts\AlertType;
use App\Alerts\Delivery\AlertMail;
use App\Alerts\Delivery\WebhookPayload;
use App\Dns\ArrayResolver;
use App\Dns\Resolver;
use App\Models\Domain;
use App\Models\NotificationChannel;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AlertDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::fake(['*' => Http::response('ok')]);
        $this->app->instance(Resolver::class, (new ArrayResolver)
            ->set('hooks.example.com', 'A', ['93.184.216.34'])
            ->set('internal.example.com', 'A', ['10.0.0.8']));
        $this->tenant = Tenant::factory()->create();
        $this->domain = Domain::factory()->for($this->tenant)->create(['name' => 'acme.test']);
    }

    private function raise(string $severity = 'warning', AlertType $type = AlertType::NoReports, string $key = 'k1')
    {
        return app(AlertManager::class)->raise($type, $severity, $key, 'Something happened', 'Details here', $this->domain);
    }

    private function channel(array $attrs): NotificationChannel
    {
        return NotificationChannel::create($attrs + ['tenant_id' => $this->tenant->id, 'name' => 'c', 'min_severity' => 'info', 'enabled' => true]);
    }

    public function test_in_app_notification_goes_to_tenant_managers_only(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $outsider = User::factory()->create();
        $owner->tenants()->attach($this->tenant, ['role' => 'owner']);
        $viewer->tenants()->attach($this->tenant, ['role' => 'viewer']);
        $outsider->tenants()->attach(Tenant::factory()->create(), ['role' => 'owner']);

        $this->raise();

        $this->assertSame(1, $owner->notifications()->count());
        $this->assertSame(0, $viewer->notifications()->count());
        $this->assertSame(0, $outsider->notifications()->count());
    }

    public function test_email_channels_respect_tenant_severity_and_type(): void
    {
        $this->channel(['type' => 'email', 'config' => ['addresses' => ['it@acme.test']]]);
        $this->channel(['type' => 'email', 'config' => ['addresses' => ['crit@acme.test']], 'min_severity' => 'critical']);
        $this->channel(['type' => 'email', 'config' => ['addresses' => ['dns@acme.test']], 'alert_types' => ['dns_changed']]);
        $this->channel(['type' => 'email', 'config' => ['addresses' => ['off@acme.test']], 'enabled' => false]);
        $this->channel(['type' => 'email', 'config' => ['addresses' => ['noc@msp.test']], 'tenant_id' => null]);
        $this->channel(['type' => 'email', 'config' => ['addresses' => ['other@other.test']], 'tenant_id' => Tenant::factory()->create()->id]);

        $this->raise('warning');

        $sentTo = Mail::sent(AlertMail::class)->flatMap(fn ($m) => array_column($m->to, 'address'))->sort()->values()->all();
        $this->assertSame(['it@acme.test', 'noc@msp.test'], $sentTo);
    }

    public function test_webhook_is_signed_and_sent_on_open_and_resolve(): void
    {
        $ch = $this->channel(['type' => 'webhook', 'config' => ['url' => 'https://hooks.example.com/dmarc', 'secret' => 'shh-secret-1234567']]);
        $alert = $this->raise();
        app(AlertManager::class)->resolve($alert->fresh());

        Http::assertSentCount(2);
        Http::assertSent(function (Request $r) {
            $body = $r->body();
            $expected = WebhookPayload::signature('shh-secret-1234567', (int) $r->header('X-Dmarc-Timestamp')[0], $body);

            return $r->url() === 'https://hooks.example.com/dmarc'
                && $r->header('X-Dmarc-Signature')[0] === $expected
                && $r['event'] === 'alert.opened'
                && $r['alert']['domain'] === 'acme.test';
        });
        Http::assertSent(fn (Request $r) => $r['event'] === 'alert.resolved');
        $this->assertNotNull($ch->fresh()->last_delivered_at);
    }

    public function test_webhook_to_private_address_is_never_sent(): void
    {
        $ch = $this->channel(['type' => 'webhook', 'config' => ['url' => 'https://internal.example.com/x', 'secret' => 'shh-secret-1234567']]);
        $this->raise();
        Http::assertNothingSent();
        $this->assertStringContainsString('public internet', $ch->fresh()->last_error);
    }

    public function test_channel_config_is_encrypted_at_rest(): void
    {
        $ch = $this->channel(['type' => 'webhook', 'config' => ['url' => 'https://hooks.example.com/x', 'secret' => 'shh-secret-1234567']]);
        $raw = \DB::table('notification_channels')->where('id', $ch->id)->value('config');
        $this->assertStringNotContainsString('shh-secret', $raw);
        $this->assertStringNotContainsString('hooks.example.com', $raw);
    }

    public function test_msp_alerts_notify_super_admins(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true])->save();
        app(AlertManager::class)->raise(AlertType::IngestFailures, 'warning', 'ingest_failures', 'Ingest broken');
        $this->assertSame(1, $admin->notifications()->count());
    }
}
