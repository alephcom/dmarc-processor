<?php

namespace App\Jobs;

use App\Alerts\Delivery\SafeWebhookTarget;
use App\Alerts\Delivery\WebhookPayload;
use App\Models\Alert;
use App\Models\NotificationChannel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class SendWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function __construct(public readonly int $channelId, public readonly int $alertId, public readonly string $event) {}

    public function handle(SafeWebhookTarget $guard): void
    {
        $channel = NotificationChannel::find($this->channelId);
        $alert = Alert::with(['tenant', 'domain'])->find($this->alertId);
        if (! $channel || ! $alert || ! $channel->enabled) {
            return;
        }
        $error = self::send($guard, $channel, WebhookPayload::for($alert, $this->event));
        if ($error !== null) {
            throw new RuntimeException($error); // retried with backoff
        }
    }

    /**
     * Deliver one payload. Returns null on success or an error message.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function send(SafeWebhookTarget $guard, NotificationChannel $channel, array $payload): ?string
    {
        try {
            $target = $guard->resolve((string) ($channel->config['url'] ?? ''));
        } catch (InvalidArgumentException $e) {
            $channel->forceFill(['last_error' => $e->getMessage()])->save();

            return null; // not retryable
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $ts = time();
        try {
            $resp = Http::timeout(10)
                ->withOptions([
                    'allow_redirects' => false,
                    // Connect to the address we validated, not a fresh lookup.
                    'curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$target['ip']}"]],
                ])
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'DMARC-Monitor-Webhook/1.0',
                    'X-Dmarc-Event' => $payload['event'],
                    'X-Dmarc-Timestamp' => (string) $ts,
                    'X-Dmarc-Signature' => WebhookPayload::signature((string) ($channel->config['secret'] ?? ''), $ts, $body),
                ])
                ->withBody($body, 'application/json')
                ->post($target['url']);
        } catch (\Throwable $e) {
            $msg = 'Request failed: '.mb_substr($e->getMessage(), 0, 300);
            $channel->forceFill(['last_error' => $msg])->save();

            return $msg;
        }

        if (! $resp->successful()) {
            $msg = "Endpoint returned HTTP {$resp->status()}";
            $channel->forceFill(['last_error' => $msg])->save();

            return $msg;
        }
        $channel->forceFill(['last_delivered_at' => now(), 'last_error' => null])->save();

        return null;
    }
}
