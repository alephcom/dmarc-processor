<?php

namespace Tests\Unit\Dns;

use App\Dns\ArrayResolver;
use App\Dns\Checks\CheckResult;
use App\Dns\Checks\SpfAnalyzer;
use PHPUnit\Framework\TestCase;

class SpfAnalyzerTest extends TestCase
{
    private function dns(array $records): ArrayResolver
    {
        $dns = new ArrayResolver;
        foreach ($records as $name => $txt) {
            $dns->set($name, 'TXT', (array) $txt);
        }

        return $dns;
    }

    public function test_simple_valid_record(): void
    {
        $dns = $this->dns([
            'example.com' => ['google-site-verification=x', 'v=spf1 include:_spf.google.com ip4:192.0.2.0/24 -all'],
            '_spf.google.com' => 'v=spf1 include:_netblocks.google.com include:_netblocks2.google.com ~all',
            '_netblocks.google.com' => 'v=spf1 ip4:35.190.247.0/24 ~all',
            '_netblocks2.google.com' => 'v=spf1 ip6:2001:4860:4000::/36 ~all',
        ]);
        $r = (new SpfAnalyzer($dns))->analyze('example.com');
        $this->assertSame(CheckResult::OK, $r->status, implode(',', $r->issueCodes()));
        $this->assertSame(3, $r->parsed['lookups']);
        $this->assertSame('-', $r->parsed['all']);
        $this->assertSame('_spf.google.com', $r->parsed['tree']['includes'][0]['domain']);
    }

    public function test_missing_and_multiple(): void
    {
        $this->assertSame(CheckResult::MISSING, (new SpfAnalyzer($this->dns([])))->analyze('example.com')->status);
        $r = (new SpfAnalyzer($this->dns(['example.com' => ['v=spf1 -all', 'v=spf1 include:x.com -all']])))->analyze('example.com');
        $this->assertTrue($r->hasIssue('multiple_records'));
    }

    public function test_counts_lookups_across_tree_and_flags_limit(): void
    {
        $records = ['example.com' => 'v=spf1 a mx include:i1.test include:i2.test include:i3.test -all'];
        for ($i = 1; $i <= 3; $i++) {
            $records["i{$i}.test"] = "v=spf1 include:j{$i}a.test include:j{$i}b.test ~all";
            $records["j{$i}a.test"] = 'v=spf1 ip4:192.0.2.1 ~all';
            $records["j{$i}b.test"] = 'v=spf1 ip4:192.0.2.2 ~all';
        }
        $dns = $this->dns($records)->set('example.com', 'A', true)->set('example.com', 'MX', ['mx.example.com']);
        $r = (new SpfAnalyzer($dns))->analyze('example.com');
        $this->assertSame(11, $r->parsed['lookups']); // a, mx, 3 includes, 6 nested includes
        $this->assertTrue($r->hasIssue('too_many_lookups'));
        $this->assertSame(CheckResult::ERROR, $r->status);
    }

    public function test_near_limit_warning(): void
    {
        $includes = implode(' ', array_map(fn ($i) => "include:s{$i}.test", range(1, 9)));
        $records = ['example.com' => "v=spf1 {$includes} -all"];
        foreach (range(1, 9) as $i) {
            $records["s{$i}.test"] = 'v=spf1 ip4:192.0.2.1 -all';
        }
        $r = (new SpfAnalyzer($this->dns($records)))->analyze('example.com');
        $this->assertSame(9, $r->parsed['lookups']);
        $this->assertSame(['near_lookup_limit'], $r->issueCodes());
    }

    public function test_void_lookups_and_missing_include(): void
    {
        $dns = $this->dns(['example.com' => 'v=spf1 a:gone1.test mx:gone2.test include:gone3.test -all']);
        $r = (new SpfAnalyzer($dns))->analyze('example.com');
        $this->assertSame(3, $r->parsed['void_lookups']);
        $this->assertTrue($r->hasIssue('too_many_void_lookups'));
        $this->assertTrue($r->hasIssue('include_missing'));
    }

    public function test_loop_detection(): void
    {
        $dns = $this->dns(['example.com' => 'v=spf1 include:a.test -all', 'a.test' => 'v=spf1 include:example.com -all']);
        $this->assertTrue((new SpfAnalyzer($dns))->analyze('example.com')->hasIssue('loop'));
    }

    public function test_all_qualifiers_and_ptr(): void
    {
        $check = fn (string $rec) => (new SpfAnalyzer($this->dns(['example.com' => $rec])))->analyze('example.com');
        $this->assertTrue($check('v=spf1 +all')->hasIssue('pass_all'));
        $this->assertTrue($check('v=spf1 ip4:192.0.2.1 ?all')->hasIssue('neutral_all'));
        $this->assertTrue($check('v=spf1 ip4:192.0.2.1')->hasIssue('no_all'));
        $this->assertTrue($check('v=spf1 ptr -all')->hasIssue('ptr'));
        $this->assertSame([], $check('v=spf1 ip4:192.0.2.1 ~all')->issueCodes());
        $this->assertTrue($check('v=spf1 ipv4:192.0.2.1 -all')->hasIssue('unknown_mechanism'));
    }

    public function test_cidr_suffix_on_implicit_a_uses_current_domain(): void
    {
        $dns = $this->dns(['example.com' => 'v=spf1 a/24 mx/24 -all'])->set('example.com', 'A', true)->set('example.com', 'MX', ['mx.example.com']);
        $r = (new SpfAnalyzer($dns))->analyze('example.com');
        $this->assertSame(0, $r->parsed['void_lookups']);
        $this->assertSame(2, $r->parsed['lookups']);
    }

    public function test_redirect(): void
    {
        $dns = $this->dns(['example.com' => 'v=spf1 redirect=_spf.example.net', '_spf.example.net' => 'v=spf1 ip4:192.0.2.0/24 -all']);
        $r = (new SpfAnalyzer($dns))->analyze('example.com');
        $this->assertSame(1, $r->parsed['lookups']);
        $this->assertFalse($r->hasIssue('no_all'));
    }

    public function test_lookup_failure(): void
    {
        $dns = new ArrayResolver(['example.com' => 'SERVFAIL']);
        $this->assertSame(CheckResult::UNKNOWN, (new SpfAnalyzer($dns))->analyze('example.com')->status);
    }
}
