<?php

namespace Tests\Unit\Dns;

use App\Dns\ArrayResolver;
use App\Dns\Checks\CheckResult;
use App\Dns\Checks\DkimChecker;
use PHPUnit\Framework\TestCase;

class DkimCheckerTest extends TestCase
{
    private static array $keys = [];

    private static function pubkey(int $bits): string
    {
        if (! isset(self::$keys[$bits])) {
            $k = openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $pem = openssl_pkey_get_details($k)['key'];
            self::$keys[$bits] = preg_replace('/-----[^-]+-----|\s/', '', $pem);
        }

        return self::$keys[$bits];
    }

    public function test_finds_keys_and_grades_them(): void
    {
        $dns = (new ArrayResolver)
            ->set('google._domainkey.example.com', 'TXT', ['v=DKIM1; k=rsa; p='.self::pubkey(2048)])
            ->set('selector1._domainkey.example.com', 'TXT', ['v=DKIM1; k=rsa; p='.self::pubkey(1024)])
            ->set('old._domainkey.example.com', 'TXT', ['v=DKIM1; p='])
            ->set('custom._domainkey.example.com', 'TXT', ['v=DKIM1; k=ed25519; p=11qYAYKxCrfVS/7TyWQHOg7hcvPapiMlrwIaaPcHURo=']);

        $r = (new DkimChecker($dns))->check('example.com', ['custom', 'old']);
        $keys = collect($r->parsed['keys'])->keyBy('selector');

        $this->assertSame(2048, $keys['google']['bits']);
        $this->assertSame(1024, $keys['selector1']['bits']);
        $this->assertTrue($keys['old']['revoked']);
        $this->assertSame('ed25519', $keys['custom']['key_type']);
        $this->assertTrue($r->hasIssue('short_key'));
        $this->assertTrue($r->hasIssue('revoked'));
        $this->assertSame(CheckResult::WARN, $r->status);
    }

    public function test_bad_key_is_error(): void
    {
        $dns = (new ArrayResolver)->set('s1._domainkey.example.com', 'TXT', ['v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtruncated']);
        $r = (new DkimChecker($dns))->check('example.com');
        $this->assertTrue($r->hasIssue('bad_key'));
        $this->assertSame(CheckResult::ERROR, $r->status);
    }

    public function test_none_found(): void
    {
        $r = (new DkimChecker(new ArrayResolver))->check('example.com');
        $this->assertSame(CheckResult::MISSING, $r->status);
        $this->assertTrue($r->hasIssue('none_found'));
    }

    public function test_rejects_invalid_selector_names(): void
    {
        $dns = new ArrayResolver;
        (new DkimChecker($dns))->check('example.com', ['ok-sel', 'bad sel', '../x', str_repeat('a', 70)]);
        $this->assertArrayHasKey('ok-sel._domainkey.example.com', $dns->queries);
        $this->assertCount(count(DkimChecker::COMMON_SELECTORS) + 1, $dns->queries);
    }
}
