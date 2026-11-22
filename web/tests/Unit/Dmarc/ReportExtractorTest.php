<?php

namespace Tests\Unit\Dmarc;

use App\Dmarc\ReportExtractor;
use App\Dmarc\ReportParseException;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class ReportExtractorTest extends TestCase
{
    private string $xml;

    protected function setUp(): void
    {
        $this->xml = file_get_contents(__DIR__.'/../../Fixtures/reports/google.xml');
    }

    private function zip(string $name, string $content): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'z');
        $z = new ZipArchive;
        $z->open($tmp, ZipArchive::OVERWRITE);
        $z->addFromString($name, $content);
        $z->close();
        $data = file_get_contents($tmp);
        unlink($tmp);

        return $data;
    }

    private function multipart(string $ctype, string $filename, string $payload): string
    {
        return "From: noreply-dmarc-support@google.com\r\n"
            ."Subject: Report domain: example.com\r\n"
            ."MIME-Version: 1.0\r\n"
            ."Content-Type: multipart/mixed;\r\n\tboundary=\"000000000000abc\"\r\n"
            ."\r\n"
            ."--000000000000abc\r\n"
            ."Content-Type: text/plain; charset=\"UTF-8\"\r\n\r\n"
            ."This is an aggregate report.\r\n"
            ."--000000000000abc\r\n"
            ."Content-Type: {$ctype}; name=\"{$filename}\"\r\n"
            ."Content-Disposition: attachment; filename=\"{$filename}\"\r\n"
            ."Content-Transfer-Encoding: base64\r\n\r\n"
            .chunk_split(base64_encode($payload), 76, "\r\n")
            ."--000000000000abc--\r\n";
    }

    public function test_extracts_zip_attachment(): void
    {
        $mail = $this->multipart('application/zip', 'google.com!example.com!1731888000!1731974399.zip', $this->zip('google.com!example.com.xml', $this->xml));
        $out = (new ReportExtractor)->extract($mail);
        $this->assertCount(1, $out);
        $this->assertSame($this->xml, $out[0]['xml']);
        $this->assertSame('google.com!example.com!1731888000!1731974399.zip', $out[0]['filename']);
    }

    public function test_extracts_gzip_attachment_even_if_mislabelled(): void
    {
        $mail = $this->multipart('application/octet-stream', 'enterprise.protection.outlook.com!example.com.xml.gz', gzencode($this->xml));
        $out = (new ReportExtractor)->extract($mail);
        $this->assertCount(1, $out);
        $this->assertSame($this->xml, $out[0]['xml']);
    }

    public function test_extracts_single_part_lf_only_message(): void
    {
        $mail = "From: dmarc@example.org\nContent-Type: application/gzip; name=report.xml.gz\nContent-Transfer-Encoding: base64\n\n"
            .chunk_split(base64_encode(gzencode($this->xml)), 76, "\n");
        $out = (new ReportExtractor)->extract($mail);
        $this->assertCount(1, $out);
        $this->assertSame($this->xml, $out[0]['xml']);
    }

    public function test_extracts_plain_xml_attachment(): void
    {
        $mail = $this->multipart('text/xml', 'report.xml', $this->xml);
        $this->assertCount(1, (new ReportExtractor)->extract($mail));
    }

    public function test_ignores_messages_without_reports(): void
    {
        $mail = "From: a@b.c\r\nContent-Type: text/plain\r\n\r\nHello, this is not a report.\r\n";
        $this->assertSame([], (new ReportExtractor)->extract($mail));
    }

    public function test_rejects_gzip_bomb(): void
    {
        $bomb = gzencode(str_repeat("\0", 60 * 1024 * 1024), 9);
        $mail = $this->multipart('application/gzip', 'x.xml.gz', $bomb);
        $this->expectException(ReportParseException::class);
        (new ReportExtractor)->extract($mail);
    }

    public function test_rejects_zip_bomb(): void
    {
        $mail = $this->multipart('application/zip', 'x.zip', $this->zip('x.xml', str_repeat('A', 60 * 1024 * 1024)));
        $this->expectException(ReportParseException::class);
        (new ReportExtractor)->extract($mail);
    }
}
