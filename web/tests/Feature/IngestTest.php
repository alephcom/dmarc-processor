<?php

namespace Tests\Feature;

use App\Models\DailyDomainStat;
use App\Models\DailySourceStat;
use App\Models\Domain;
use App\Models\InboundMessage;
use App\Models\Report;
use App\Models\ReportRecord;
use App\Support\IngestSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class IngestTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret-test-secret-test-secret-123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['dmarc.ingest_hmac_secret' => self::SECRET, 'dmarc.reports_domain' => 'reports.msp.test']);
        Storage::fake('local');
    }

    private function signedGet(string $path, ?int $ts = null): TestResponse
    {
        $ts ??= time();

        return $this->get($path, [
            'X-Timestamp' => (string) $ts,
            'X-Signature' => IngestSignature::sign(self::SECRET, $ts, 'GET', $path, ''),
            'Accept' => 'application/json',
        ]);
    }

    private function signedIngest(array $payload, ?string $secret = null): TestResponse
    {
        $body = json_encode($payload);
        $ts = time();

        return $this->call('POST', '/internal/ingest', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_TIMESTAMP' => (string) $ts,
            'HTTP_X_SIGNATURE' => IngestSignature::sign($secret ?? self::SECRET, $ts, 'POST', '/internal/ingest', $body),
        ], $body);
    }

    private function reportMail(string $xmlFixture = 'google.xml', ?string $replaceDomain = null): string
    {
        $xml = file_get_contents(base_path('tests/Fixtures/reports/'.$xmlFixture));
        if ($replaceDomain) {
            $xml = str_replace('<domain>example.com</domain>', "<domain>{$replaceDomain}</domain>", $xml);
        }

        return "From: noreply-dmarc-support@google.com\r\nSubject: Report domain: example.com Submitter: google.com\r\n"
            ."MIME-Version: 1.0\r\nContent-Type: application/gzip; name=\"r.xml.gz\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            .chunk_split(base64_encode(gzencode($xml)));
    }

    private function payload(Domain $domain, string $mail, string $id = '1731974400-abc'): array
    {
        return [
            'id' => $id,
            'received_at' => '2024-11-19T01:02:03Z',
            'remote_ip' => '209.85.220.1',
            'helo' => 'mail-sor-f41.google.com',
            'mail_from' => 'noreply-dmarc-support@google.com',
            'recipients' => [$domain->ruaAddress()],
            'tls' => true,
            'message_b64' => base64_encode($mail),
        ];
    }

    public function test_recipient_lookup(): void
    {
        $active = Domain::factory()->create();
        $paused = Domain::factory()->create(['status' => Domain::STATUS_PAUSED]);

        $this->signedGet('/internal/recipients/'.$active->rua_token)->assertOk();
        $this->signedGet('/internal/recipients/'.$paused->rua_token)->assertNotFound();
        $this->signedGet('/internal/recipients/aaaaaaaaaa')->assertNotFound();
    }

    public function test_rejects_bad_or_stale_signatures(): void
    {
        $d = Domain::factory()->create();
        $this->get('/internal/recipients/'.$d->rua_token, ['Accept' => 'application/json'])->assertUnauthorized();
        $this->signedGet('/internal/recipients/'.$d->rua_token, time() - 3600)->assertUnauthorized();
        $this->signedIngest($this->payload($d, $this->reportMail()), str_repeat('x', 40))->assertUnauthorized();
        $this->assertSame(0, InboundMessage::count());
    }

    public function test_full_ingest_pipeline(): void
    {
        $domain = Domain::factory()->create(['name' => 'example.com']);

        $this->signedIngest($this->payload($domain, $this->reportMail()))->assertStatus(202);

        $msg = InboundMessage::sole();
        $this->assertSame(InboundMessage::STATUS_PROCESSED, $msg->status, (string) $msg->error);
        $this->assertSame(1, $msg->reports_count);
        $this->assertStringContainsString('Report domain: example.com', $msg->subject);
        Storage::disk('local')->assertExists($msg->raw_path);

        $report = Report::sole();
        $this->assertSame('google.com', $report->reporter_org);
        $this->assertSame(45, $report->message_count);
        $this->assertSame(2, ReportRecord::count());

        $stat = DailyDomainStat::sole();
        $this->assertSame('2024-11-18', $stat->day->toDateString());
        $this->assertSame(45, $stat->total);
        $this->assertSame(42, $stat->dmarc_pass);
        $this->assertSame(2, DailySourceStat::count());

        $domain->refresh();
        $this->assertSame(Domain::STATUS_ACTIVE, $domain->status);
        $this->assertNotNull($domain->first_report_at);
    }

    public function test_ingest_is_idempotent_and_reports_are_deduplicated(): void
    {
        $domain = Domain::factory()->create(['name' => 'example.com']);
        $mail = $this->reportMail();

        $this->signedIngest($this->payload($domain, $mail, 'spool-1'))->assertStatus(202);
        $this->signedIngest($this->payload($domain, $mail, 'spool-1'))->assertStatus(202); // receiver retry
        $this->assertSame(1, InboundMessage::count());

        $this->signedIngest($this->payload($domain, $mail, 'spool-2'))->assertStatus(202); // reporter resent
        $this->assertSame(2, InboundMessage::count());
        $this->assertSame(1, Report::count());
        $this->assertSame(45, DailyDomainStat::sole()->total);
    }

    public function test_subdomain_reports_are_accepted(): void
    {
        $domain = Domain::factory()->create(['name' => 'example.com']);
        $this->signedIngest($this->payload($domain, $this->reportMail('google.xml', 'mail.example.com')))->assertStatus(202);
        $this->assertSame('mail.example.com', Report::sole()->policy_domain);
    }

    public function test_reports_for_other_domains_are_quarantined(): void
    {
        $victim = Domain::factory()->create(['name' => 'victim.com']);
        $attacker = Domain::factory()->create(['name' => 'example.org']);

        // A report about victim.com sent to example.org's address must not be stored.
        $this->signedIngest($this->payload($attacker, $this->reportMail('google.xml', 'victim.com')))->assertStatus(202);

        $this->assertSame(InboundMessage::STATUS_QUARANTINED, InboundMessage::sole()->status);
        $this->assertSame(0, Report::count());
        $this->assertSame(0, $victim->reports()->count());
        $this->assertSame(Domain::STATUS_PENDING, $attacker->fresh()->status);
    }

    public function test_messages_without_reports_are_ignored(): void
    {
        $domain = Domain::factory()->create();
        $mail = "From: someone@example.net\r\nSubject: hello\r\nContent-Type: text/plain\r\n\r\nNot a report\r\n";
        $this->signedIngest($this->payload($domain, $mail))->assertStatus(202);
        $this->assertSame(InboundMessage::STATUS_IGNORED, InboundMessage::sole()->status);
    }

    public function test_corrupt_report_is_marked_failed(): void
    {
        $domain = Domain::factory()->create(['name' => 'example.com']);
        $mail = "From: x@y.z\r\nContent-Type: text/xml; name=r.xml\r\n\r\n<?xml version=\"1.0\"?><feedback><report_metadata>";
        $this->signedIngest($this->payload($domain, $mail))->assertStatus(202);
        $msg = InboundMessage::sole();
        $this->assertSame(InboundMessage::STATUS_FAILED, $msg->status);
        $this->assertNotEmpty($msg->error);
    }

    public function test_rejects_foreign_recipient_domain(): void
    {
        $domain = Domain::factory()->create();
        $p = $this->payload($domain, $this->reportMail());
        $p['recipients'] = ['rua-'.$domain->rua_token.'@elsewhere.test'];
        $this->signedIngest($p)->assertStatus(422);
    }
}
