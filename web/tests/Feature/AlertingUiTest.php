<?php

namespace Tests\Feature;

use App\Alerts\AlertManager;
use App\Alerts\AlertType;
use App\Dns\ArrayResolver;
use App\Dns\Resolver;
use App\Filament\Admin\Pages\DefaultAlertSettings;
use App\Filament\Admin\Resources\NotificationChannels\Pages\ListNotificationChannels as AdminListChannels;
use App\Filament\App\Pages\AlertSettingsPage;
use App\Filament\App\Resources\Alerts\AlertResource;
use App\Filament\App\Resources\Alerts\Pages\ListAlerts;
use App\Filament\App\Resources\Alerts\Pages\ViewAlert;
use App\Filament\App\Resources\Domains\Pages\ViewDomain;
use App\Filament\App\Resources\NotificationChannels\NotificationChannelResource;
use App\Filament\App\Resources\NotificationChannels\Pages\CreateNotificationChannel;
use App\Filament\App\Resources\NotificationChannels\Pages\ListNotificationChannels;
use App\Jobs\DeliverAlert;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Domain;
use App\Models\NotificationChannel;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class AlertingUiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Tenant $other;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([DeliverAlert::class]);
        $this->app->instance(Resolver::class, (new ArrayResolver)->set('hooks.example.com', 'A', ['93.184.216.34'])->set('lan.example.com', 'A', ['192.168.1.10']));
        $this->tenant = Tenant::factory()->create();
        $this->other = Tenant::factory()->create();
        $this->domain = Domain::factory()->for($this->tenant)->create(['name' => 'acme.test']);
    }

    private function actAs(string $role, ?Tenant $tenant = null): User
    {
        $tenant ??= $this->tenant;
        $u = User::factory()->create();
        $u->tenants()->attach($tenant, ['role' => $role]);
        $this->actingAs($u);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tenant);
        Filament::bootCurrentPanel();

        return $u;
    }

    private function alertFor(Domain $d, string $key = 'k'): Alert
    {
        return app(AlertManager::class)->raise(AlertType::NoReports, 'warning', $key.$d->id, "No reports for {$d->name}", 'body', $d);
    }

    public function test_alerts_are_tenant_scoped(): void
    {
        $mine = $this->alertFor($this->domain);
        $theirs = $this->alertFor(Domain::factory()->for($this->other)->create(['name' => 'other.test']));
        $msp = app(AlertManager::class)->raise(AlertType::IngestFailures, 'warning', 'ingest_failures', 'Platform issue');
        $this->actAs('viewer');

        Livewire::test(ListAlerts::class)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$theirs, $msp]);

        // Resolved alerts are hidden by default and shown with the "Resolved" filter.
        $old = $this->alertFor($this->domain, 'old');
        app(AlertManager::class)->resolve($old);
        Livewire::test(ListAlerts::class)->assertCanNotSeeTableRecords([$old])
            ->filterTable('open', false)->assertCanSeeTableRecords([$old])->assertCanNotSeeTableRecords([$mine]);
        $this->get(AlertResource::getUrl('view', ['record' => $mine], tenant: $this->tenant))->assertOk();
        $this->get(AlertResource::getUrl('view', ['record' => $theirs], tenant: $this->tenant))->assertNotFound();
        $this->get(AlertResource::getUrl('view', ['record' => $msp], tenant: $this->tenant))->assertNotFound();
    }

    public function test_managers_can_resolve_viewers_cannot(): void
    {
        $alert = $this->alertFor($this->domain);
        $this->actAs('viewer');
        Livewire::test(ViewAlert::class, ['record' => $alert->getRouteKey()])->assertActionHidden('resolve');

        $this->actAs('admin');
        Livewire::test(ViewAlert::class, ['record' => $alert->getRouteKey()])->callAction('resolve');
        $this->assertSame('manual', $alert->fresh()->resolution);
    }

    public function test_domain_page_shows_open_alerts_and_policy_guide(): void
    {
        $this->alertFor($this->domain);
        $this->actAs('viewer');
        $this->get(ViewDomain::getUrl(['record' => $this->domain], tenant: $this->tenant))
            ->assertOk()->assertSee('Open alerts')->assertSee('No reports for acme.test')
            ->assertSee('Policy guide')->assertSee('Finish setup first');
    }

    public function test_channels_are_manager_only_and_tenant_scoped(): void
    {
        $mine = NotificationChannel::create(['tenant_id' => $this->tenant->id, 'name' => 'Mine', 'type' => 'email', 'config' => ['addresses' => ['a@acme.test']]]);
        $theirs = NotificationChannel::create(['tenant_id' => $this->other->id, 'name' => 'Theirs', 'type' => 'email', 'config' => ['addresses' => ['b@other.test']]]);
        $msp = NotificationChannel::create(['tenant_id' => null, 'name' => 'NOC', 'type' => 'email', 'config' => ['addresses' => ['noc@msp.test']]]);

        $this->actAs('viewer');
        $this->get(NotificationChannelResource::getUrl('index', tenant: $this->tenant))->assertForbidden();

        $this->actAs('owner');
        Livewire::test(ListNotificationChannels::class)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$theirs, $msp]);
        $this->get(NotificationChannelResource::getUrl('edit', ['record' => $theirs], tenant: $this->tenant))->assertNotFound();
    }

    public function test_create_email_and_webhook_channels_with_validation(): void
    {
        $this->actAs('admin');
        Livewire::test(CreateNotificationChannel::class)
            ->fillForm(['name' => 'IT', 'type' => 'email', 'config.addresses' => ['it@acme.test', 'ops@acme.test'], 'min_severity' => 'warning'])
            ->call('create')->assertHasNoFormErrors();
        $ch = NotificationChannel::firstWhere('name', 'IT');
        $this->assertSame($this->tenant->id, $ch->tenant_id);
        $this->assertSame(['it@acme.test', 'ops@acme.test'], $ch->config['addresses']);

        Livewire::test(CreateNotificationChannel::class)
            ->fillForm(['name' => 'Bad', 'type' => 'email', 'config.addresses' => ['not-an-email']])
            ->call('create')->assertHasFormErrors();

        Livewire::test(CreateNotificationChannel::class)
            ->fillForm(['name' => 'LAN hook', 'type' => 'webhook', 'config.url' => 'https://lan.example.com/hook', 'config.secret' => str_repeat('s', 20)])
            ->call('create')->assertHasFormErrors(['config.url']);

        Livewire::test(CreateNotificationChannel::class)
            ->fillForm(['name' => 'PSA', 'type' => 'webhook', 'config.url' => 'https://hooks.example.com/psa', 'config.secret' => str_repeat('s', 20)])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame('https://hooks.example.com/psa', NotificationChannel::firstWhere('name', 'PSA')->config['url']);
    }

    public function test_tenant_alert_settings(): void
    {
        $this->actAs('viewer');
        $this->get(AlertSettingsPage::getUrl(tenant: $this->tenant))->assertForbidden();

        $this->actAs('owner');
        Livewire::test(AlertSettingsPage::class)
            ->assertFormSet(['digest_frequency' => 'weekly', 'rules.no_reports.hours' => 72])
            ->fillForm(['digest_frequency' => 'monthly', 'rules.no_reports.hours' => 48, 'rules.new_sender.enabled' => false])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame('monthly', $this->tenant->fresh()->digest_frequency);
        $this->assertSame(['hours' => 48], AlertRule::where('tenant_id', $this->tenant->id)->where('type', 'no_reports')->value('thresholds'));
        $this->assertFalse((bool) AlertRule::where('tenant_id', $this->tenant->id)->where('type', 'new_sender')->value('enabled'));
        $this->assertSame(0, AlertRule::where('tenant_id', $this->tenant->id)->where('type', 'ingest_failures')->count(), 'MSP-only type not exposed');
    }

    public function test_msp_default_settings_and_channels(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true])->save();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(DefaultAlertSettings::class)
            ->fillForm(['rules.ingest_failures.min_failures' => 5])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame(['min_failures' => 5], AlertRule::whereNull('tenant_id')->where('type', 'ingest_failures')->value('thresholds'));

        $msp = NotificationChannel::create(['tenant_id' => null, 'name' => 'NOC', 'type' => 'email', 'config' => ['addresses' => ['noc@msp.test']]]);
        $tenantCh = NotificationChannel::create(['tenant_id' => $this->tenant->id, 'name' => 'T', 'type' => 'email', 'config' => ['addresses' => ['t@acme.test']]]);
        Livewire::test(AdminListChannels::class)->assertCanSeeTableRecords([$msp])->assertCanNotSeeTableRecords([$tenantCh]);
    }
}
