<?php

namespace App\Dmarc;

use App\Models\Report;
use Illuminate\Support\Facades\Storage;

/**
 * Finds the original XML of an imported report by re-reading the raw message it came in.
 * Only the XML is returned, never the surrounding email.
 */
class RawReportLocator
{
    public function __construct(
        private readonly ReportExtractor $extractor,
        private readonly AggregateReportParser $parser,
    ) {}

    public function xml(Report $report): ?string
    {
        $path = $report->inboundMessage?->raw_path;
        $gz = $path ? Storage::disk(config('dmarc.raw_disk'))->get($path) : null;
        $raw = $gz === null ? false : @gzdecode($gz);
        if ($raw === false) {
            return null;
        }

        try {
            $attachments = $this->extractor->extract($raw);
        } catch (ReportParseException) {
            return null;
        }
        foreach ($attachments as $att) {
            try {
                $parsed = $this->parser->parse($att['xml']);
            } catch (ReportParseException) {
                continue;
            }
            // Stored values are truncated to the column length on import.
            if (mb_substr($parsed->reportId, 0, 255) === $report->report_id && mb_substr($parsed->orgName, 0, 255) === $report->reporter_org) {
                return $att['xml'];
            }
        }

        return null;
    }
}
