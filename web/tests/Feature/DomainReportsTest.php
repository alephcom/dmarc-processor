<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Domains\DomainResource;
use App\Filament\App\Resources\Domains\Pages\DomainReports;
use App\Models\Domain;
use App\Models\InboundMessage;
use App\Models\Report;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ReportImporter;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class DomainReportsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->tenant = Tenant::factory()->create();
        $this->domain = Domain::factory()->for($this->tenant)->create(['name' => 'example.com']);
    }

    private function actAs(string $role, ?Tenant $tenant = null): void
    {
        $u = User::factory()->create();
        $u->tenants()->attach($tenant ?? $this->tenant, ['role' => $role]);
        $this->actingAs($u);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tenant ?? $this->tenant);
        Filament::bootCurrentPanel();
    }

    /** Imports the Google fixture for a domain through the real pipeline, so the raw message is on disk. */
    private function importFixture(Domain $domain): Report
    {
        $xml = file_get_contents(base_path('tests/Fixtures/reports/google.xml'));
        $mail = "From: noreply-dmarc-support@google.com\r\nSubject: Report\r\nMIME-Version: 1.0\r\n"
            ."Content-Type: application/gzip; name=\"r.xml.gz\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            .chunk_split(base64_encode(gzencode($xml)));
        $path = 'raw/'.uniqid().'.eml.gz';
        Storage::disk('local')->put($path, gzencode($mail));
        $msg = InboundMessage::create([
            'spool_id' => uniqid(), 'domain_id' => $domain->id, 'rua_token' => $domain->rua_token, 'remote_ip' => '192.0.2.1',
            'mail_from' => 'noreply-dmarc-support@google.com', 'raw_path' => $path, 'size' => strlen($mail),
            'status' => InboundMessage::STATUS_PENDING, 'received_at' => now(),
        ]);
        app(ReportImporter::class)->import($msg);

        return Report::where('domain_id', $domain->id)->sole();
    }

    public function test_viewer_can_browse_reports_records_and_download_xml(): void
    {
        $report = $this->importFixture($this->domain);
        $this->actAs(User::ROLE_VIEWER);

        $this->get(DomainResource::getUrl('reports', ['record' => $this->domain], tenant: $this->tenant))->assertOk();

        Livewire::test(DomainReports::class, ['record' => $this->domain->getRouteKey()])
            ->assertCanSeeTableRecords([$report])
            ->assertSee('google.com')
            ->assertSee('93.3%') // 42 of 45 messages pass
            ->mountTableAction('records', $report)
            ->assertMountedActionModalSee(['Not aligned', 'outlook.com', '13021866539224883051']);

        Livewire::test(DomainReports::class, ['record' => $this->domain->getRouteKey()])
            ->callTableAction('xml', $report)
            ->assertFileDownloaded(contentType: 'application/xml');
    }

    public function test_xml_download_warns_when_raw_message_is_gone(): void
    {
        $report = $this->importFixture($this->domain);
        Storage::disk('local')->delete($report->inboundMessage->raw_path);
        $this->actAs(User::ROLE_VIEWER);

        Livewire::test(DomainReports::class, ['record' => $this->domain->getRouteKey()])
            ->callTableAction('xml', $report)
            ->assertNotified('The original report is no longer available');
    }

    public function test_other_tenants_cannot_see_reports(): void
    {
        $this->importFixture($this->domain);
        $other = Tenant::factory()->create();
        $this->actAs(User::ROLE_ADMIN, $other);

        $this->get(DomainResource::getUrl('reports', ['record' => $this->domain], tenant: $other))->assertNotFound();
        $this->get(DomainResource::getUrl('reports', ['record' => $this->domain], tenant: $this->tenant))->assertNotFound();
    }
}
