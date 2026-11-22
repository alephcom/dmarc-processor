<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Domains\Pages\ListDomains;
use App\Filament\App\Widgets\TenantDomainHealth;
use App\Filament\Support\CsvExport;
use App\Jobs\ScanDomainDns;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class DomainImportExportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
    }

    private function actAs(string $role): void
    {
        $u = User::factory()->create();
        $u->tenants()->attach($this->tenant, ['role' => $role]);
        $this->actingAs($u);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->tenant);
        Filament::bootCurrentPanel();
    }

    public function test_admin_can_add_several_domains(): void
    {
        Queue::fake();
        Domain::factory()->for(Tenant::factory())->create(['name' => 'taken.test']);
        $this->actAs(User::ROLE_ADMIN);

        Livewire::test(ListDomains::class)
            ->callAction('bulkAdd', ['domains' => "One.test\n two.test, taken.test;not_a_domain\n\"three.test\"\none.test."])
            ->assertNotified();

        $this->assertEqualsCanonicalizing(['one.test', 'two.test', 'three.test'], $this->tenant->domains()->pluck('name')->all());
        $this->assertSame(1, Domain::withoutGlobalScopes()->where('name', 'taken.test')->count());
        Queue::assertPushed(ScanDomainDns::class, 3);
    }

    public function test_bulk_add_is_limited(): void
    {
        $this->actAs(User::ROLE_ADMIN);
        $many = implode("\n", array_map(fn ($i) => "d{$i}.test", range(1, ListDomains::BULK_LIMIT + 1)));

        Livewire::test(ListDomains::class)->callAction('bulkAdd', ['domains' => $many])->assertNotified('Too many domains');
        $this->assertSame(0, Domain::withoutGlobalScopes()->count());
    }

    public function test_viewer_cannot_add_domains(): void
    {
        $this->actAs(User::ROLE_VIEWER);
        Livewire::test(ListDomains::class)->assertActionHidden('bulkAdd');
    }

    public function test_domains_export_contains_only_own_domains(): void
    {
        Domain::factory()->for($this->tenant)->create(['name' => 'mine.test']);
        Domain::factory()->for(Tenant::factory())->create(['name' => 'theirs.test']);
        $this->actAs(User::ROLE_VIEWER);

        $csv = Livewire::test(TenantDomainHealth::class)->callTableAction('exportCsv')->effects['download']['content'] ?? null;
        $csv = base64_decode((string) $csv);

        $this->assertStringContainsString('Domain,', $csv);
        $this->assertStringContainsString('mine.test', $csv);
        $this->assertStringNotContainsString('theirs.test', $csv);
    }

    public function test_csv_cells_cannot_run_as_formulas(): void
    {
        $this->assertSame("'=HYPERLINK(\"x\")", CsvExport::cell('=HYPERLINK("x")'));
        $this->assertSame("'-1+2", CsvExport::cell('-1+2'));
        $this->assertSame("'@SUM(A1)", CsvExport::cell('@SUM(A1)'));
        $this->assertSame('mail.example.com', CsvExport::cell('mail.example.com'));
        $this->assertSame('42', CsvExport::cell(42));
        $this->assertSame('', CsvExport::cell(null));
    }
}
