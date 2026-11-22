<?php

namespace App\Dns\Checks;

use App\Dns\DnsLookupException;
use App\Dns\Resolver;

/**
 * DKIM selectors can't be listed from DNS, so we probe common ones plus any the customer
 * supplies or that we have seen in their DMARC reports.
 */
class DkimChecker
{
    /** Selectors used by common providers. */
    public const COMMON_SELECTORS = [
        'google',                                   // Google Workspace
        'selector1', 'selector2',                   // Microsoft 365
        'k1', 'k2', 'k3',                           // Mailchimp / Mandrill
        's1', 's2',                                 // SendGrid
        'default', 'dkim', 'mail', 'smtp',          // generic / hosting panels
        'mxvault',                                  // MXroute
        'zendesk1', 'zendesk2',                     // Zendesk
        'hs1', 'hs2',                               // HubSpot
        'cm',                                       // Campaign Monitor
        'mailjet', 'mte1',                          // Mailjet, Mailchimp Transactional
        'fm1', 'fm2', 'fm3',                        // Fastmail
        'protonmail', 'protonmail2', 'protonmail3', // Proton
        'sig1',                                     // iCloud custom domains
        'everlytickey1', 'everlytickey2',           // Everlytic
        'amazonses',                                // Amazon SES (legacy)
    ];

    public const MAX_SELECTORS = 60;

    public function __construct(private readonly Resolver $dns) {}

    /** @param list<string> $extraSelectors */
    public function check(string $domain, array $extraSelectors = []): CheckResult
    {
        $selectors = [];
        foreach ([...$extraSelectors, ...self::COMMON_SELECTORS] as $s) {
            $s = strtolower(trim((string) $s));
            if ($s !== '' && preg_match('/^[a-z0-9._-]{1,63}$/', $s)) {
                $selectors[$s] = true;
            }
        }
        $selectors = array_slice(array_keys($selectors), 0, self::MAX_SELECTORS);

        $found = [];
        $issues = [];
        $failures = 0;
        foreach ($selectors as $sel) {
            try {
                $records = $this->dns->txt("{$sel}._domainkey.{$domain}");
            } catch (DnsLookupException) {
                $failures++;

                continue;
            }
            $record = null;
            foreach ($records as $r) {
                if (preg_match('/(^|;)\s*(v\s*=\s*DKIM1|p\s*=)/i', $r)) {
                    $record = $r;
                    break;
                }
            }
            if ($record === null) {
                continue;
            }
            $key = $this->analyzeKey($sel, $record);
            $found[] = $key;
            array_push($issues, ...$key['issues']);
        }

        if ($failures === count($selectors) && $selectors !== []) {
            return CheckResult::lookupFailed('dkim', 'all DKIM lookups failed');
        }

        $active = array_filter($found, fn ($k) => ! $k['revoked']);
        $parsed = [
            'selectors_checked' => $selectors,
            'keys' => array_map(fn ($k) => array_diff_key($k, ['issues' => 1]), $found),
        ];

        if ($active === []) {
            $issues[] = Issue::warning('none_found', 'We didn\'t find any DKIM keys at the selectors we checked.', 'Turn on DKIM signing in each service that sends your email (Microsoft 365, Google Workspace, your newsletter tool...). If it is already on, add its selector name below so we can check it.');

            return new CheckResult('dkim', CheckResult::MISSING, null, $parsed, array_map(fn ($i) => $i, $issues));
        }

        return CheckResult::fromIssues('dkim', null, $parsed, $issues);
    }

    /**
     * @return array{selector: string, key_type: string, bits: ?int, revoked: bool, testing: bool, issues: list<Issue>}
     */
    private function analyzeKey(string $selector, string $record): array
    {
        $tags = [];
        foreach (explode(';', $record) as $part) {
            if (str_contains($part, '=')) {
                [$k, $v] = array_map('trim', explode('=', $part, 2));
                $tags[strtolower($k)] ??= preg_replace('/\s+/', '', $v);
            }
        }
        $type = strtolower($tags['k'] ?? 'rsa');
        $p = $tags['p'] ?? '';
        $issues = [];
        $bits = null;
        $revoked = $p === '';
        $testing = in_array('y', explode(':', strtolower($tags['t'] ?? '')), true);

        if ($revoked) {
            $issues[] = Issue::info('revoked', "Selector \"{$selector}\" has an empty key (revoked). That's fine if it's no longer used.");
        } elseif ($type === 'rsa') {
            $bits = $this->rsaBits($p);
            if ($bits === null) {
                $issues[] = Issue::error('bad_key', "Selector \"{$selector}\": the public key can't be read, so signatures will fail.", 'Copy the DKIM record again from your email provider; it may have been truncated or split incorrectly.');
            } elseif ($bits < 1024) {
                $issues[] = Issue::error('weak_key', "Selector \"{$selector}\" uses a {$bits}-bit key, which receivers reject.", 'Generate a new 2048-bit key in your email provider.');
            } elseif ($bits < 2048) {
                $issues[] = Issue::warning('short_key', "Selector \"{$selector}\" uses a {$bits}-bit key. 2048-bit is recommended.", 'Rotate to a 2048-bit key when your provider allows it.');
            }
        } elseif ($type !== 'ed25519') {
            $issues[] = Issue::error('unknown_key_type', "Selector \"{$selector}\" has unknown key type k={$type}.");
        }
        if ($testing) {
            $issues[] = Issue::info('testing', "Selector \"{$selector}\" is in test mode (t=y); some receivers treat its signatures as unsigned.");
        }

        return ['selector' => $selector, 'key_type' => $type, 'bits' => $bits, 'revoked' => $revoked, 'testing' => $testing, 'issues' => $issues];
    }

    private function rsaBits(string $b64): ?int
    {
        $der = base64_decode($b64, true);
        if ($der === false || $der === '') {
            return null;
        }
        // DKIM keys are normally SubjectPublicKeyInfo; some tools publish bare PKCS#1 RSAPublicKey.
        foreach (['PUBLIC KEY', 'RSA PUBLIC KEY'] as $label) {
            $pem = "-----BEGIN {$label}-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END {$label}-----\n";
            $key = @openssl_pkey_get_public($pem);
            if ($key !== false) {
                $details = openssl_pkey_get_details($key);

                return $details['bits'] ?? null;
            }
        }

        return null;
    }
}
