<?php

namespace App\Services;

use App\Alerts\AlertEvaluator;
use App\Dmarc\AggregateReportParser;
use App\Dmarc\Data\AggregateReport;
use App\Dmarc\ReportExtractor;
use App\Dmarc\ReportParseException;
use App\Intel\SourceRegistry;
use App\Jobs\EnrichSourceIps;
use App\Models\Domain;
use App\Models\InboundMessage;
use App\Models\Report;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Turns a stored inbound message into reports + records, then refreshes rollups.
 */
class ReportImporter
{
    public function __construct(
        private readonly ReportExtractor $extractor,
        private readonly AggregateReportParser $parser,
        private readonly StatsAggregator $stats,
        private readonly SourceRegistry $registry,
        private readonly AlertEvaluator $alerts,
    ) {}

    /** @var list<int> */
    private array $importedReportIds = [];

    public function import(InboundMessage $msg): void
    {
        $domain = $msg->domain;
        if (! $domain) {
            $this->finish($msg, InboundMessage::STATUS_FAILED, 'Message is not linked to a domain');

            return;
        }

        $gz = Storage::disk(config('dmarc.raw_disk'))->get($msg->raw_path);
        $raw = $gz === null ? false : @gzdecode($gz);
        if ($raw === false) {
            $this->finish($msg, InboundMessage::STATUS_FAILED, 'Raw message missing or unreadable');

            return;
        }

        try {
            $attachments = $this->extractor->extract($raw);
        } catch (ReportParseException $e) {
            $this->finish($msg, InboundMessage::STATUS_FAILED, $e->getMessage());

            return;
        }
        if ($attachments === []) {
            $this->finish($msg, InboundMessage::STATUS_IGNORED, 'No aggregate report attachment found');

            return;
        }

        $imported = 0;
        $this->importedReportIds = [];
        $errors = [];
        $quarantined = false;
        $touchedDays = [];
        foreach ($attachments as $att) {
            try {
                $report = $this->parser->parse($att['xml']);
            } catch (ReportParseException $e) {
                $errors[] = ($att['filename'] ?? 'attachment').': '.$e->getMessage();

                continue;
            }
            if (! $domain->covers($report->policy->domain)) {
                // Someone used this domain's token to submit data about another domain.
                $quarantined = true;
                $errors[] = "Report is for {$report->policy->domain}, not {$domain->name}";

                continue;
            }
            $day = $this->store($domain, $msg, $report);
            if ($day !== null) {
                $imported++;
                $touchedDays[$day] = true;
            }
        }

        foreach (array_keys($touchedDays) as $day) {
            $this->stats->refreshDay($domain->id, $day);
        }
        if ($touchedDays !== []) {
            $this->registry->sync($domain->id);
            $ips = DB::table('report_records')->whereIn('report_id', $this->importedReportIds)->distinct()->pluck('source_ip')->all();
            foreach (array_chunk($ips, 100) as $chunk) {
                EnrichSourceIps::dispatch($chunk);
            }
        }

        if ($imported > 0) {
            $now = now();
            $domain->forceFill([
                'status' => $domain->status === Domain::STATUS_PENDING ? Domain::STATUS_ACTIVE : $domain->status,
                'first_report_at' => $domain->first_report_at ?? $now,
                'last_report_at' => $now,
            ])->save();
            $this->alerts->afterImport($domain);
        }

        $status = match (true) {
            $quarantined && $imported === 0 => InboundMessage::STATUS_QUARANTINED,
            $imported === 0 && $errors !== [] => InboundMessage::STATUS_FAILED,
            default => InboundMessage::STATUS_PROCESSED,
        };
        $msg->reports_count = $imported;
        $this->finish($msg, $status, $errors ? implode("\n", $errors) : null);
    }

    /** @return string|null the stats day touched, or null if the report was a duplicate */
    private function store(Domain $domain, InboundMessage $msg, AggregateReport $r): ?string
    {
        $day = $r->dateBegin->toDateString();

        try {
            DB::transaction(function () use ($domain, $msg, $r, $day) {
                $report = Report::create([
                    'domain_id' => $domain->id,
                    'inbound_message_id' => $msg->id,
                    'reporter_org' => mb_substr($r->orgName, 0, 255),
                    'reporter_email' => $r->email ? mb_substr($r->email, 0, 255) : null,
                    'report_id' => mb_substr($r->reportId, 0, 255),
                    'policy_domain' => $r->policy->domain,
                    'date_begin' => $r->dateBegin,
                    'date_end' => $r->dateEnd,
                    'policy_published' => $r->policy->toArray(),
                    'errors' => $r->errors ?: null,
                    'message_count' => $r->totalMessages(),
                ]);
                $this->importedReportIds[] = $report->id;

                $rows = [];
                foreach ($r->records as $rec) {
                    $rows[] = [
                        'report_id' => $report->id,
                        'domain_id' => $domain->id,
                        'day' => $day,
                        'source_ip' => $rec->sourceIp,
                        'count' => $rec->count,
                        'disposition' => $rec->disposition,
                        'dkim_aligned' => $rec->dkimAligned,
                        'spf_aligned' => $rec->spfAligned,
                        'header_from' => mb_substr($rec->headerFrom, 0, 255),
                        'envelope_from' => $rec->envelopeFrom ? mb_substr($rec->envelopeFrom, 0, 255) : null,
                        'envelope_to' => $rec->envelopeTo ? mb_substr($rec->envelopeTo, 0, 255) : null,
                        'dkim_results' => json_encode($rec->dkimResults),
                        'spf_results' => json_encode($rec->spfResults),
                        'reasons' => $rec->reasons ? json_encode($rec->reasons) : null,
                    ];
                }
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('report_records')->insert($chunk);
                }
            });
        } catch (UniqueConstraintViolationException) {
            return null; // already imported (reporters often resend)
        }

        return $day;
    }

    private function finish(InboundMessage $msg, string $status, ?string $error): void
    {
        $msg->forceFill(['status' => $status, 'error' => $error, 'processed_at' => now()])->save();
    }
}
