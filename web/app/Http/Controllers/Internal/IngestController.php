<?php

namespace App\Http\Controllers\Internal;

use App\Jobs\ProcessInboundMessage;
use App\Models\Domain;
use App\Models\InboundMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class IngestController
{
    /** smtp-receiver asks this during RCPT TO. 200 = accept, 404 = reject. */
    public function recipient(string $token): JsonResponse
    {
        $domain = Domain::where('rua_token', strtolower($token))->first();
        if (! $domain || ! $domain->acceptsReports()) {
            return response()->json(['valid' => false], 404);
        }

        return response()->json(['valid' => true]);
    }

    public function ingest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            'received_at' => ['required', 'date'],
            'remote_ip' => ['nullable', 'ip'],
            'mail_from' => ['nullable', 'string', 'max:255'],
            'recipients' => ['required', 'array', 'min:1', 'max:10'],
            'recipients.*' => ['string', 'max:255'],
            'message_b64' => ['required', 'string'],
        ]);

        $raw = base64_decode($data['message_b64'], true);
        if ($raw === false || $raw === '') {
            return response()->json(['error' => 'message_b64 is not valid base64'], 422);
        }

        $reportsDomain = strtolower(config('dmarc.reports_domain'));
        $tokens = [];
        foreach ($data['recipients'] as $rcpt) {
            if (preg_match('/^<?rua-([a-z2-7]{10})@(.+?)>?$/i', trim($rcpt), $m) && strtolower($m[2]) === $reportsDomain) {
                $tokens[strtolower($m[1])] = true;
            }
        }
        if ($tokens === []) {
            return response()->json(['error' => 'no valid recipients'], 422);
        }

        $receivedAt = Carbon::parse($data['received_at'])->utc();
        $subject = $this->subject($raw);
        $disk = Storage::disk(config('dmarc.raw_disk'));
        $path = config('dmarc.raw_prefix').'/'.$receivedAt->format('Y/m/d').'/'.$data['id'].'.eml.gz';
        if (! $disk->exists($path)) {
            $disk->put($path, gzencode($raw, 6));
        }

        $created = [];
        foreach (array_keys($tokens) as $token) {
            $domain = Domain::where('rua_token', $token)->first();
            // Idempotent: the receiver may retry the same spool id after a timeout.
            $msg = DB::transaction(fn () => InboundMessage::firstOrCreate(
                ['spool_id' => $data['id'], 'rua_token' => $token],
                [
                    'domain_id' => $domain?->id,
                    'remote_ip' => $data['remote_ip'] ?? null,
                    'mail_from' => isset($data['mail_from']) ? mb_substr($data['mail_from'], 0, 255) : null,
                    'subject' => $subject,
                    'raw_path' => $path,
                    'size' => strlen($raw),
                    'status' => $domain ? InboundMessage::STATUS_PENDING : InboundMessage::STATUS_FAILED,
                    'error' => $domain ? null : 'Unknown reporting token',
                    'received_at' => $receivedAt,
                ],
            ));
            if ($msg->wasRecentlyCreated && $domain) {
                ProcessInboundMessage::dispatch($msg->id);
            }
            $created[] = $msg->id;
        }

        return response()->json(['accepted' => $created], 202);
    }

    private function subject(string $raw): ?string
    {
        $headerEnd = strpos($raw, "\r\n\r\n") ?: strpos($raw, "\n\n") ?: min(strlen($raw), 16384);
        if (preg_match('/^Subject:[ \t]*(.*(?:\r?\n[ \t].*)*)/mi', substr($raw, 0, $headerEnd), $m)) {
            $s = preg_replace('/\r?\n[ \t]+/', ' ', $m[1]);
            $decoded = @iconv_mime_decode($s, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

            return mb_substr(trim($decoded !== false ? $decoded : $s), 0, 255);
        }

        return null;
    }
}
