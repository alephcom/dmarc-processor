<?php

namespace Tests\Unit\Dns;

use App\Dns\ArrayResolver;
use App\Dns\Checks\CheckResult;
use App\Dns\Checks\DmarcAnalyzer;
use App\Dns\Checks\DmarcRecord;
use App\Dns\Checks\DmarcRecordBuilder;
use PHPUnit\Framework\TestCase;

class DmarcTest extends TestCase
{
    private const OURS = 'rua-abcdefghij@reports.msp.test';

    private function analyze(array $txt): CheckResult
    {
        $dns = (new ArrayResolver)->set('_dmarc.example.com', 'TXT', $txt);

        return (new DmarcAnalyzer($dns))->analyze('example.com', self::OURS);
    }

    public function test_missing_record(): void
    {
        $r = $this->analyze([]);
        $this->assertSame(CheckResult::MISSING, $r->status);
    }

    public function test_ignores_unrelated_txt_and_detects_missing_rua(): void
    {
        $r = $this->analyze(['some-verification=abc', 'v=DMARC1; p=quarantine; rua=mailto:dmarc@example.com']);
        $this->assertSame(CheckResult::ERROR, $r->status);
        $this->assertSame(['rua_missing'], $r->issueCodes());
        $this->assertSame('quarantine', $r->parsed['policy']);
    }

    public function test_valid_record_with_our_rua(): void
    {
        $r = $this->analyze(['v=DMARC1; p=none; rua=mailto:dmarc@example.com,mailto:'.strtoupper(self::OURS).'!10m']);
        $this->assertSame(CheckResult::OK, $r->status, implode(',', $r->issueCodes()));
        $this->assertTrue($r->parsed['has_our_rua']);
        $this->assertTrue($r->hasIssue('policy_none'));
    }

    public function test_multiple_records(): void
    {
        $r = $this->analyze(['v=DMARC1; p=none', 'v=DMARC1; p=reject']);
        $this->assertTrue($r->hasIssue('multiple_records'));
        $this->assertSame(CheckResult::ERROR, $r->status);
    }

    public function test_invalid_values(): void
    {
        $r = $this->analyze(['v=DMARC1; p=block; pct=150; adkim=x; rua=dmarc@example.com; foo=bar']);
        foreach (['invalid_policy', 'invalid_pct', 'invalid_adkim', 'invalid_rua', 'rua_missing', 'unknown_tags'] as $code) {
            $this->assertTrue($r->hasIssue($code), "expected {$code}, got ".implode(',', $r->issueCodes()));
        }
    }

    public function test_missing_policy(): void
    {
        $this->assertTrue($this->analyze(['v=DMARC1; rua=mailto:'.self::OURS])->hasIssue('missing_policy'));
    }

    public function test_builder_creates_starter_record(): void
    {
        $this->assertSame('v=DMARC1; p=none; rua=mailto:'.self::OURS, (new DmarcRecordBuilder)->build(null, self::OURS));
    }

    public function test_builder_merges_and_preserves_existing_settings(): void
    {
        $existing = DmarcRecord::parse('v=DMARC1; rua=mailto:a@example.com; p=quarantine; pct=50; ruf=mailto:f@example.com; adkim=s; x-custom=1');
        $this->assertSame(
            'v=DMARC1; p=quarantine; pct=50; rua=mailto:a@example.com,mailto:'.self::OURS.'; ruf=mailto:f@example.com; adkim=s; x-custom=1',
            (new DmarcRecordBuilder)->build($existing, self::OURS),
        );
    }

    public function test_builder_does_not_duplicate_our_address(): void
    {
        $existing = DmarcRecord::parse('v=DMARC1; p=none; rua=mailto:'.self::OURS);
        $this->assertSame('v=DMARC1; p=none; rua=mailto:'.self::OURS, (new DmarcRecordBuilder)->build($existing, self::OURS));
    }

    public function test_builder_sets_policy_and_pct_for_progression(): void
    {
        $existing = DmarcRecord::parse('v=DMARC1; p=none; rua=mailto:'.self::OURS);
        $b = new DmarcRecordBuilder;
        $this->assertSame('v=DMARC1; p=quarantine; pct=25; rua=mailto:'.self::OURS, $b->build($existing, self::OURS, 'quarantine', 25));
        $q = DmarcRecord::parse('v=DMARC1; p=quarantine; pct=25; rua=mailto:'.self::OURS);
        $this->assertSame('v=DMARC1; p=reject; rua=mailto:'.self::OURS, $b->build($q, self::OURS, 'reject', 100));
    }

    public function test_builder_replaces_invalid_policy_with_none(): void
    {
        $existing = DmarcRecord::parse('v=DMARC1; p=block');
        $this->assertStringStartsWith('v=DMARC1; p=none;', (new DmarcRecordBuilder)->build($existing, self::OURS));
    }
}
