<?php

namespace App\Dns\Checks;

use App\Dns\DnsLookupException;
use App\Dns\Resolver;

class DmarcAnalyzer
{
    public function __construct(private readonly Resolver $dns) {}

    public function analyze(string $domain, string $ourRuaAddress): CheckResult
    {
        $name = '_dmarc.'.$domain;
        try {
            $records = array_values(array_filter($this->dns->txt($name), [DmarcRecord::class, 'looksLikeDmarc']));
        } catch (DnsLookupException $e) {
            return CheckResult::lookupFailed('dmarc', $e->getMessage());
        }

        if ($records === []) {
            return new CheckResult('dmarc', CheckResult::MISSING, null, [], [
                Issue::error('missing', "There is no DMARC record at {$name}.", 'Publish the DMARC record shown below as a TXT record.'),
            ]);
        }
        if (count($records) > 1) {
            return CheckResult::fromIssues('dmarc', implode("\n", $records), [], [
                Issue::error('multiple_records', 'There is more than one DMARC record. Mailbox providers ignore DMARC entirely when this happens.', 'Delete all but one DMARC TXT record at '.$name.'.'),
            ]);
        }

        $raw = $records[0];
        $rec = DmarcRecord::parse($raw);
        $issues = [];

        if (! preg_match('/^\s*v\s*=\s*DMARC1\s*(;|$)/', $raw)) {
            $issues[] = Issue::error('bad_version', 'The record must start with exactly "v=DMARC1".');
        }

        $p = $rec->get('p');
        if ($p === null) {
            $issues[] = Issue::error('missing_policy', 'The record has no p= policy tag, so receivers will treat it as invalid.', 'Add "p=none" to start monitoring.');
        } elseif ($rec->policy() === null) {
            $issues[] = Issue::error('invalid_policy', "p={$p} is not valid. Use none, quarantine or reject.");
        } elseif ($rec->policy() === 'none') {
            $issues[] = Issue::info('policy_none', 'Policy is "none": you are monitoring only, so spoofed mail is not blocked yet. That is the right place to start.');
        }

        foreach (['sp', 'np'] as $tag) {
            $v = $rec->get($tag);
            if ($v !== null && ! in_array(strtolower($v), ['none', 'quarantine', 'reject'], true)) {
                $issues[] = Issue::error('invalid_'.$tag, "{$tag}={$v} is not valid. Use none, quarantine or reject.");
            }
        }

        $pct = $rec->get('pct');
        if ($pct !== null) {
            if (! ctype_digit($pct) || (int) $pct > 100) {
                $issues[] = Issue::error('invalid_pct', "pct={$pct} must be a whole number from 0 to 100.");
            } elseif ((int) $pct < 100 && $rec->policy() !== 'none') {
                $issues[] = Issue::info('partial_pct', "The policy applies to {$pct}% of failing mail.");
            }
        }

        foreach (['adkim', 'aspf'] as $tag) {
            $v = $rec->get($tag);
            if ($v !== null && ! in_array(strtolower($v), ['r', 's'], true)) {
                $issues[] = Issue::error('invalid_'.$tag, "{$tag}={$v} is not valid. Use r (relaxed) or s (strict).");
            }
        }

        $uris = $rec->ruaUris();
        foreach ($uris as $uri) {
            if (! preg_match('/^mailto:[^@\s]+@[^@\s!]+(![0-9]+[kmgt]?)?$/i', $uri)) {
                $issues[] = Issue::error('invalid_rua', "\"{$uri}\" is not a valid rua address. Each one must look like mailto:someone@example.com.");
            }
        }
        $hasOurs = in_array(strtolower($ourRuaAddress), $rec->ruaAddresses(), true);
        if (! $hasOurs) {
            $issues[] = Issue::error('rua_missing', 'Reports are not being sent to us yet: our reporting address is not in the rua tag.', 'Publish the updated record from the setup wizard. It adds our address and keeps your existing settings.');
        }

        if ($rec->get('ruf') !== null) {
            $issues[] = Issue::info('ruf_present', 'The record asks for forensic (ruf) reports. Few providers send these and they can contain personal data; we don\'t process them.');
        }

        $unknown = array_diff(array_keys($rec->tags), DmarcRecord::KNOWN_TAGS);
        if ($unknown !== []) {
            $issues[] = Issue::warning('unknown_tags', 'Unknown tags will be ignored by receivers: '.implode(', ', $unknown).'.');
        }

        return CheckResult::fromIssues('dmarc', $raw, [
            'tags' => $rec->tags,
            'policy' => $rec->policy(),
            'pct' => $rec->pct(),
            'has_our_rua' => $hasOurs,
            'rua' => $rec->ruaAddresses(),
        ], $issues);
    }
}
