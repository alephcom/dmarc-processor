<?php

namespace App\Jobs;

use App\Models\InboundMessage;
use App\Services\ReportImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ProcessInboundMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public readonly int $inboundMessageId) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('inbound-'.$this->inboundMessageId))->expireAfter(600)];
    }

    public function handle(ReportImporter $importer): void
    {
        $msg = InboundMessage::with('domain')->find($this->inboundMessageId);
        if (! $msg || ! in_array($msg->status, [InboundMessage::STATUS_PENDING, InboundMessage::STATUS_FAILED], true)) {
            return;
        }
        $importer->import($msg);
    }

    public function failed(\Throwable $e): void
    {
        InboundMessage::whereKey($this->inboundMessageId)->update([
            'status' => InboundMessage::STATUS_FAILED,
            'error' => mb_substr($e->getMessage(), 0, 2000),
        ]);
    }
}
