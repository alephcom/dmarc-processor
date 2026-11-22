<?php

namespace Tests\Unit\Intel;

use App\Intel\MmdbAsnDatabase;
use PHPUnit\Framework\TestCase;

class MmdbAsnDatabaseTest extends TestCase
{
    private function db(string $file): MmdbAsnDatabase
    {
        return new MmdbAsnDatabase(__DIR__.'/../../Fixtures/ipdb/'.$file);
    }

    public function test_reads_ipinfo_lite_layout(): void
    {
        $db = $this->db('ipinfo-lite-test.mmdb');
        $this->assertTrue($db->available());
        $r = $db->lookup('209.85.220.41');
        $this->assertSame(15169, $r->asn);
        $this->assertSame('Google LLC', $r->org);
        $this->assertSame('US', $r->country);
        $this->assertSame(8075, $db->lookup('2a01:111:f400:fe5a::701')->asn);
        $this->assertNull($db->lookup('203.0.113.1'));
    }

    public function test_reads_dbip_geolite_layout(): void
    {
        $r = $this->db('dbip-asn-test.mmdb')->lookup('198.51.100.7');
        $this->assertSame(64500, $r->asn);
        $this->assertSame('Example Hosting Ltd', $r->org);
    }

    public function test_missing_file_and_bad_input(): void
    {
        $db = new MmdbAsnDatabase('/nonexistent.mmdb');
        $this->assertFalse($db->available());
        $this->assertNull($db->lookup('8.8.8.8'));
        $this->assertNull($this->db('ipinfo-lite-test.mmdb')->lookup('not-an-ip'));
    }
}
