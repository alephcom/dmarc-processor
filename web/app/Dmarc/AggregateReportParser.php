<?php

namespace App\Dmarc;

use App\Dmarc\Data\AggregateReport;
use App\Dmarc\Data\PolicyPublished;
use App\Dmarc\Data\ReportRecord;
use Carbon\CarbonImmutable;
use SimpleXMLElement;

/**
 * Parses RFC 7489 Appendix C aggregate reports, tolerating common real-world quirks
 * (namespaced DMARC 2.0 drafts, missing optional elements, odd casing, whitespace).
 */
class AggregateReportParser
{
    public const MAX_XML_BYTES = 50 * 1024 * 1024;

    public const MAX_RECORDS = 200_000;

    public function parse(string $xml): AggregateReport
    {
        if (strlen($xml) > self::MAX_XML_BYTES) {
            throw new ReportParseException('Report XML exceeds size limit');
        }
        $xml = $this->stripBom($xml);
        // Reject DTDs outright: aggregate reports never need them, and this rules out entity tricks.
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new ReportParseException('Report XML must not contain a DOCTYPE');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $root = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOCDATA);
            if ($root === false) {
                $err = libxml_get_last_error();
                throw new ReportParseException('Invalid XML: '.trim($err ? $err->message : 'unknown error'));
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        // Normalise away any default namespace (e.g. urn:ietf:params:xml:ns:dmarc-2.0).
        $root = $this->withoutNamespaces($root);

        if (strtolower($root->getName()) !== 'feedback') {
            throw new ReportParseException('Root element is not <feedback>');
        }

        $meta = $root->report_metadata;
        $policyNode = $root->policy_published;
        if (! $meta->count() || ! $policyNode->count()) {
            throw new ReportParseException('Missing report_metadata or policy_published');
        }

        $orgName = $this->text($meta->org_name);
        $reportId = $this->text($meta->report_id);
        $begin = $this->text($meta->date_range->begin);
        $end = $this->text($meta->date_range->end);
        if ($orgName === null || $reportId === null || ! ctype_digit((string) $begin) || ! ctype_digit((string) $end)) {
            throw new ReportParseException('report_metadata is missing org_name, report_id or a valid date_range');
        }

        $domain = $this->text($policyNode->domain);
        if ($domain === null) {
            throw new ReportParseException('policy_published/domain is missing');
        }

        $policy = new PolicyPublished(
            domain: strtolower(rtrim($domain, '.')),
            p: $this->enum($policyNode->p, ['none', 'quarantine', 'reject'], 'none'),
            sp: $this->enumOrNull($policyNode->sp, ['none', 'quarantine', 'reject']),
            np: $this->enumOrNull($policyNode->np, ['none', 'quarantine', 'reject']),
            adkim: $this->enum($policyNode->adkim, ['r', 's'], 'r'),
            aspf: $this->enum($policyNode->aspf, ['r', 's'], 'r'),
            pct: max(0, min(100, (int) ($this->text($policyNode->pct) ?? 100))),
            fo: $this->text($policyNode->fo),
        );

        $records = [];
        foreach ($root->record as $rec) {
            if (count($records) >= self::MAX_RECORDS) {
                throw new ReportParseException('Report has too many records');
            }
            $record = $this->parseRecord($rec);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        $errors = [];
        foreach ($meta->error as $e) {
            if (($t = $this->text($e)) !== null) {
                $errors[] = $t;
            }
        }

        return new AggregateReport(
            orgName: $orgName,
            email: $this->text($meta->email),
            reportId: $reportId,
            dateBegin: CarbonImmutable::createFromTimestampUTC((int) $begin),
            dateEnd: CarbonImmutable::createFromTimestampUTC((int) $end),
            policy: $policy,
            records: $records,
            errors: $errors,
            extraContact: $this->text($meta->extra_contact_info),
        );
    }

    private function parseRecord(SimpleXMLElement $rec): ?ReportRecord
    {
        $row = $rec->row;
        $ip = $this->text($row->source_ip);
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null; // skip unusable rows rather than failing the whole report
        }
        $count = (int) ($this->text($row->count) ?? 0);
        if ($count <= 0) {
            return null;
        }
        $pe = $row->policy_evaluated;

        $reasons = [];
        foreach ($pe->reason as $r) {
            $type = $this->text($r->type);
            if ($type !== null) {
                $reasons[] = ['type' => strtolower($type), 'comment' => $this->text($r->comment)];
            }
        }

        $dkim = [];
        foreach ($rec->auth_results->dkim as $d) {
            $dom = $this->text($d->domain);
            if ($dom !== null) {
                $dkim[] = [
                    'domain' => strtolower($dom),
                    'selector' => $this->text($d->selector),
                    'result' => strtolower($this->text($d->result) ?? 'none'),
                ];
            }
        }
        $spf = [];
        foreach ($rec->auth_results->spf as $s) {
            $dom = $this->text($s->domain);
            if ($dom !== null) {
                $spf[] = [
                    'domain' => strtolower($dom),
                    'scope' => $this->text($s->scope),
                    'result' => strtolower($this->text($s->result) ?? 'none'),
                ];
            }
        }

        $ids = $rec->identifiers;
        $headerFrom = $this->text($ids->header_from) ?? '';

        return new ReportRecord(
            sourceIp: inet_ntop(inet_pton($ip)), // canonical form
            count: $count,
            disposition: $this->enum($pe->disposition, ['none', 'quarantine', 'reject'], 'none'),
            dkimAligned: strtolower($this->text($pe->dkim) ?? '') === 'pass',
            spfAligned: strtolower($this->text($pe->spf) ?? '') === 'pass',
            headerFrom: strtolower(rtrim($headerFrom, '.')),
            envelopeFrom: ($ef = $this->text($ids->envelope_from)) !== null ? strtolower($ef) : null,
            envelopeTo: ($et = $this->text($ids->envelope_to)) !== null ? strtolower($et) : null,
            reasons: $reasons,
            dkimResults: $dkim,
            spfResults: $spf,
        );
    }

    private function text(?SimpleXMLElement $node): ?string
    {
        // Missing elements come back as empty SimpleXMLElements, so an empty string covers both cases.
        $v = $node === null ? '' : trim((string) $node);

        return $v === '' ? null : $v;
    }

    /** @param list<string> $allowed */
    private function enum(?SimpleXMLElement $node, array $allowed, string $default): string
    {
        return $this->enumOrNull($node, $allowed) ?? $default;
    }

    /** @param list<string> $allowed */
    private function enumOrNull(?SimpleXMLElement $node, array $allowed): ?string
    {
        $v = strtolower($this->text($node) ?? '');

        return in_array($v, $allowed, true) ? $v : null;
    }

    private function stripBom(string $xml): string
    {
        return str_starts_with($xml, "\xEF\xBB\xBF") ? substr($xml, 3) : $xml;
    }

    private function withoutNamespaces(SimpleXMLElement $root): SimpleXMLElement
    {
        if ($root->getNamespaces() === []) {
            return $root;
        }
        $xml = $root->asXML();
        $xml = preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', $xml);
        $xml = preg_replace('/<(\/?)\w+:(\w)/', '<$1$2', $xml);

        $node = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOCDATA);
        if ($node === false) {
            throw new ReportParseException('Could not normalise XML namespaces');
        }

        return $node;
    }
}
