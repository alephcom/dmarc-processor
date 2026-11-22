<?php

namespace App\Jobs;

use App\Alerts\Delivery\AlertLinks;
use App\Alerts\Delivery\AlertMail;
use App\Models\Alert;
use App\Models\NotificationChannel;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Fans an alert out to in-app notifications and to every channel that wants it:
 * the tenant's own channels plus the MSP's channels (tenant_id NULL), which see everything.
 */
class DeliverAlert implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $alertId, public readonly string $event) {}

    public function handle(): void
    {
        $alert = Alert::with(['tenant', 'domain'])->find($this->alertId);
        if (! $alert) {
            return;
        }

        if ($this->event === 'opened') {
            $this->inApp($alert);
        }

        $channels = NotificationChannel::where(fn ($q) => $q->whereNull('tenant_id')->when($alert->tenant_id, fn ($q) => $q->orWhere('tenant_id', $alert->tenant_id)))->get();
        foreach ($channels as $channel) {
            if (! $channel->wants($alert)) {
                continue;
            }
            if ($channel->type === NotificationChannel::WEBHOOK) {
                SendWebhook::dispatch($channel->id, $alert->id, $this->event);
            } elseif ($this->event === 'opened') {
                $this->email($channel, $alert);
            }
        }
    }

    private function inApp(Alert $alert): void
    {
        $users = $alert->tenant ? $alert->tenant->managers()->get() : User::where('is_super_admin', true)->get();
        if ($users->isEmpty()) {
            return;
        }
        $n = Notification::make()
            ->title($alert->title)
            ->body(str($alert->body)->limit(200)->toString())
            ->actions([Action::make('view')->label('View')->url(AlertLinks::url($alert))]);
        $alert->severity === 'critical' ? $n->danger() : ($alert->severity === 'warning' ? $n->warning() : $n->info());
        $n->sendToDatabase($users);
    }

    private function email(NotificationChannel $channel, Alert $alert): void
    {
        $addresses = array_values(array_filter($channel->config['addresses'] ?? [], fn ($a) => filter_var($a, FILTER_VALIDATE_EMAIL)));
        if ($addresses === []) {
            return;
        }
        try {
            Mail::to($addresses)->send(new AlertMail($alert));
            $channel->forceFill(['last_delivered_at' => now(), 'last_error' => null])->save();
        } catch (Throwable $e) {
            $channel->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 1000)])->save();
        }
    }
}
