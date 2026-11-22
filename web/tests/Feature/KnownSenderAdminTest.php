<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\KnownSenders\Pages\EditKnownSender;
use App\Filament\Admin\Resources\KnownSenders\Pages\ListKnownSenders;
use App\Jobs\EnrichSourceIps;
use App\Models\Domain;
use App\Models\KnownSender;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class KnownSenderAdminTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): void
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();
        $this->actingAs($u);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_editing_a_sender_queues_reidentification(): void
    {
        $this->superAdmin();
        Queue::fake();
        $sender = KnownSender::firstWhere('name', 'SendGrid');

        Livewire::test(ListKnownSenders::class)->searchTable('SendGrid')->assertCanSeeTableRecords([$sender]);
        Livewire::test(EditKnownSender::class, ['record' => $sender->getRouteKey()])
            ->fillForm(['rdns_suffixes' => ['sendgrid.net', 'sendgrid.com']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['sendgrid.net', 'sendgrid.com'], $sender->fresh()->rdns_suffixes);
        Queue::assertPushed(QueuedCommand::class);
    }

    public function test_rejects_invalid_domains(): void
    {
        $this->superAdmin();
        $sender = KnownSender::firstWhere('name', 'SendGrid');
        Livewire::test(EditKnownSender::class, ['record' => $sender->getRouteKey()])
            ->fillForm(['spf_includes' => ['not a domain']])
            ->call('save')
            ->assertHasFormErrors();
    }

    public function test_reidentify_command_batches_ips(): void
    {
        Queue::fake();
        $d = Domain::factory()->create();
        foreach (range(1, 150) as $i) {
            DB::table('daily_source_stats')->insert(['domain_id' => $d->id, 'day' => '2026-11-01', 'source_ip' => "192.0.2.{$i}", 'source_key' => "ip:192.0.2.{$i}", 'total' => 1]);
        }
        $this->artisan('dmarc:reidentify-sources')->expectsOutput('Queued re-identification of 150 IPs.');
        Queue::assertPushed(EnrichSourceIps::class, 2);
        Queue::assertPushed(EnrichSourceIps::class, fn ($job) => $job->force);
    }
}
