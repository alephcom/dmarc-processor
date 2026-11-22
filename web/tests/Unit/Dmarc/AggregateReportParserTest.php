<?php

namespace Tests\Unit\Dmarc;

use App\Dmarc\AggregateReportParser;
use App\Dmarc\ReportParseException;
use PHPUnit\Framework\TestCase;

class AggregateReportParserTest extends TestCase
{
    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../../Fixtures/reports/'.$name);
    }

    public function test_parses_google_report(): void
    {
        $r = (new AggregateReportParser)->parse($this->fixture('google.xml'));

        $this->assertSame('google.com', $r->orgName);
        $this->assertSame('13021866539224883051', $r->reportId);
        $this->assertSame('2024-11-18T00:00:00+00:00', $r->dateBegin->toIso8601String());
        $this->assertSame('example.com', $r->policy->domain);
        $this->assertSame('none', $r->policy->p);
        $this->assertSame(100, $r->policy->pct);
        $this->assertCount(2, $r->records);
        $this->assertSame(45, $r->totalMessages());

        $first = $r->records[0];
        $this->assertSame('209.85.220.41', $first->sourceIp);
        $this->assertTrue($first->dmarcPass());
        $this->assertSame([['domain' => 'example.com', 'selector' => 'google', 'result' => 'pass']], $first->dkimResults);

        $second = $r->records[1];
        $this->assertSame('2a01:111:f400:fe5a::701', $second->sourceIp);
        $this->assertFalse($second->dmarcPass());
        $this->assertSame('forwarded', $second->reasons[0]['type']);
        $this->assertSame('softfail', $second->spfResults[0]['result']);
    }

    public function test_parses_microsoft_report_and_normalises_case(): void
    {
        $r = (new AggregateReportParser)->parse($this->fixture('microsoft.xml'));

        $this->assertSame('Enterprise Outlook', $r->orgName);
        $this->assertSame('example.com', $r->policy->domain);
        $this->assertSame('quarantine', $r->policy->p);
        $this->assertSame(50, $r->policy->pct);
        $rec = $r->records[0];
        $this->assertSame('quarantine', $rec->disposition);
        $this->assertSame('bounce.mailer.example.net', $rec->envelopeFrom);
        $this->assertSame('mfrom', $rec->spfResults[0]['scope']);
        $this->assertFalse($rec->dmarcPass());
    }

    public function test_parses_namespaced_report_and_skips_bad_rows(): void
    {
        $r = (new AggregateReportParser)->parse($this->fixture('dmarcbis-namespaced.xml'));

        $this->assertSame('Yahoo', $r->orgName);
        $this->assertSame('sub.example.com', $r->policy->domain);
        $this->assertSame('reject', $r->policy->p);
        $this->assertSame('r', $r->policy->adkim); // default when absent
        $this->assertCount(1, $r->records);       // the not-an-ip row is skipped
        $this->assertSame(['Some records were skipped'], $r->errors);
    }

    public function test_rejects_doctype_entities(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><feedback>&e;</feedback>';
        $this->expectException(ReportParseException::class);
        (new AggregateReportParser)->parse($xml);
    }

    public function test_rejects_invalid_xml(): void
    {
        $this->expectException(ReportParseException::class);
        (new AggregateReportParser)->parse('<feedback><report_metadata>');
    }

    public function test_rejects_missing_metadata(): void
    {
        $this->expectException(ReportParseException::class);
        (new AggregateReportParser)->parse('<feedback><policy_published><domain>x.com</domain></policy_published></feedback>');
    }
}
