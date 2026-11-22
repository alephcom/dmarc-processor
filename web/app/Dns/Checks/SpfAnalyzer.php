<?php

namespace App\Dns\Checks;

use App\Dns\DnsLookupException;
use App\Dns\Resolver;

/**
 * Evaluates an SPF record the way a receiver would for the RFC 7208 processing limits:
 * at most 10 DNS-querying terms (include, a, mx, ptr, exists, redirect) across the whole
 * tree, and at most 2 "void" lookups (names that return nothing).
 */
class SpfAnalyzer
{
    public const MAX_LOOKUPS = 10;

    public const MAX_VOIDS = 2;

    private const MAX_DEPTH = 10;

    private const LOOKUP_MECHANISMS = ['include', 'a', 'mx', 'ptr', 'exists'];

    private int $lookups;

    private int $voids;

    /** @var list<Issue> */
    private array $issues;

    /** @var array<string, true> */
    private array $visiting;

    public function __construct(private readonly Resolver $dns) {}

    public function analyze(string $domain): CheckResult
    {
        $this->lookups = 0;
        $this->voids = 0;
        $this->issues = [];
        $this->visiting = [];

        try {
            $records = $this->spfRecords($domain);
        } catch (DnsLookupException $e) {
            return CheckResult::lookupFailed('spf', $e->getMessage());
        }

        if ($records === []) {
            return new CheckResult('spf', CheckResult::MISSING, null, [], [
                Issue::warning('missing', "{$domain} has no SPF record.", 'Add an SPF record listing the services that send email for this domain, e.g. "v=spf1 include:_spf.google.com -all". DMARC can still pass through DKIM alone, but SPF gives a second route.'),
            ]);
        }
        if (count($records) > 1) {
            return CheckResult::fromIssues('spf', implode("\n", $records), [], [
                Issue::error('multiple_records', 'There is more than one SPF record. Receivers treat this as a permanent error and SPF will fail for all mail.', 'Merge them into a single "v=spf1 ..." record.'),
            ]);
        }

        try {
            $tree = $this->evaluate($domain, $records[0], 0);
        } catch (DnsLookupException $e) {
            return CheckResult::lookupFailed('spf', $e->getMessage());
        }

        if ($this->lookups > self::MAX_LOOKUPS) {
            $this->issues[] = Issue::error('too_many_lookups', "The record needs {$this->lookups} DNS lookups; the limit is ".self::MAX_LOOKUPS.'. Receivers return a permanent error and SPF fails for all your mail.', 'Remove includes for services you no longer use, replace "a"/"mx"/"ptr" with ip4:/ip6: ranges, or use subdomains for some senders.');
        } elseif ($this->lookups >= self::MAX_LOOKUPS - 1) {
            $this->issues[] = Issue::warning('near_lookup_limit', "The record uses {$this->lookups} of ".self::MAX_LOOKUPS.' allowed DNS lookups. Adding one more sending service could break SPF.');
        }
        if ($this->voids > self::MAX_VOIDS) {
            $this->issues[] = Issue::error('too_many_void_lookups', "{$this->voids} lookups return no records (limit ".self::MAX_VOIDS.'). Receivers may treat this as a permanent error.', 'Remove includes or mechanisms that point at names that no longer exist.');
        }

        $all = $tree['all'];
        if ($all === null && $tree['redirect'] === null) {
            $this->issues[] = Issue::warning('no_all', 'The record has no "all" mechanism, so mail from unlisted servers gets a neutral result.', 'End the record with "~all" or "-all".');
        } elseif ($all === '+') {
            $this->issues[] = Issue::error('pass_all', '"+all" lets any server on the internet pass SPF for your domain.', 'Change it to "~all" or "-all".');
        } elseif ($all === '?') {
            $this->issues[] = Issue::warning('neutral_all', '"?all" gives no protection: unlisted servers get a neutral result.', 'Change it to "~all" or "-all".');
        }

        return CheckResult::fromIssues('spf', $records[0], [
            'lookups' => $this->lookups,
            'void_lookups' => $this->voids,
            'all' => $all,
            'tree' => $tree,
        ], $this->issues);
    }

    /** @return list<string> */
    private function spfRecords(string $domain): array
    {
        return array_values(array_filter($this->dns->txt($domain), fn ($t) => (bool) preg_match('/^v=spf1(\s|$)/i', trim($t))));
    }

    /**
     * @return array{domain: string, record: string, all: ?string, redirect: ?string, includes: list<array<string, mixed>>, ip_count: int}
     */
    private function evaluate(string $domain, string $record, int $depth): array
    {
        $domain = strtolower($domain);
        $this->visiting[$domain] = true;
        $node = ['domain' => $domain, 'record' => $record, 'all' => null, 'redirect' => null, 'includes' => [], 'ip_count' => 0];

        $terms = preg_split('/\s+/', trim($record));
        array_shift($terms); // v=spf1

        foreach ($terms as $term) {
            if ($term === '') {
                continue;
            }
            if (preg_match('/^(redirect|exp)=(.+)$/i', $term, $m)) {
                if (strtolower($m[1]) === 'redirect') {
                    $node['redirect'] = strtolower($m[2]);
                }

                continue;
            }

            $qualifier = '+';
            if (in_array($term[0], ['+', '-', '~', '?'], true)) {
                $qualifier = $term[0];
                $term = substr($term, 1);
            }
            // mechanism[:domain-spec][/cidr], e.g. "a", "a/24", "a:mail.example.com/24", "ip6:2001:db8::/32"
            if (! preg_match('/^([a-z0-9]+)(?::([^\/]*))?(\/.*)?$/i', $term, $m)) {
                $this->addOnce(Issue::error('unknown_mechanism', "Unknown SPF term \"{$term}\" in {$domain}'s record. Receivers return a permanent error.", 'Check the record for typos.'));

                continue;
            }
            $mech = strtolower($m[1]);
            $target = isset($m[2]) && $m[2] !== '' ? strtolower($m[2]) : null;

            switch ($mech) {
                case 'all':
                    $node['all'] = $qualifier;
                    break;
                case 'ip4':
                case 'ip6':
                    $node['ip_count']++;
                    break;
                case 'include':
                    $this->lookups++;
                    $node['includes'][] = $this->include((string) $target, $depth);
                    break;
                case 'a':
                    $this->lookups++;
                    if (! $this->hasMacro($target) && ! $this->dns->hasAddress($target ?: $domain)) {
                        $this->voids++;
                    }
                    break;
                case 'mx':
                    $this->lookups++;
                    if (! $this->hasMacro($target) && $this->dns->mx($target ?: $domain) === []) {
                        $this->voids++;
                    }
                    break;
                case 'ptr':
                    $this->lookups++;
                    $this->addOnce(Issue::warning('ptr', 'The "ptr" mechanism is deprecated, slow, and ignored by some receivers.', 'Replace it with ip4:/ip6: ranges or an include from your provider.'));
                    break;
                case 'exists':
                    $this->lookups++;
                    break;
                default:
                    $this->addOnce(Issue::error('unknown_mechanism', "Unknown SPF term \"{$term}\" in {$domain}'s record. Receivers return a permanent error.", 'Check the record for typos.'));
            }
        }

        // RFC 7208 §6.1: redirect is ignored when an "all" mechanism is present.
        if ($node['redirect'] !== null && $node['all'] === null) {
            $this->lookups++;
            $node['includes'][] = $this->include($node['redirect'], $depth, true);
        }

        unset($this->visiting[$domain]);

        return $node;
    }

    /** @return array<string, mixed> */
    private function include(string $target, int $depth, bool $isRedirect = false): array
    {
        $target = strtolower($target);
        $kind = $isRedirect ? 'redirect' : 'include';
        if ($this->hasMacro($target)) {
            return ['domain' => $target, 'kind' => $kind, 'note' => 'uses macros; not expanded'];
        }
        if (isset($this->visiting[$target])) {
            $this->addOnce(Issue::error('loop', "SPF includes loop back to {$target}. Receivers return a permanent error."));

            return ['domain' => $target, 'kind' => $kind, 'note' => 'loop'];
        }
        if ($depth >= self::MAX_DEPTH) {
            return ['domain' => $target, 'kind' => $kind, 'note' => 'too deep'];
        }

        $records = $this->spfRecords($target);
        if ($records === []) {
            $this->voids++;
            $this->issues[] = Issue::error('include_missing', "{$kind}:{$target} has no SPF record. Receivers return a permanent error.", "Remove {$kind}:{$target} or correct the name.");

            return ['domain' => $target, 'kind' => $kind, 'note' => 'no SPF record'];
        }
        if (count($records) > 1) {
            $this->issues[] = Issue::error('include_multiple', "{$target} publishes more than one SPF record.");
        }

        return ['kind' => $kind] + $this->evaluate($target, $records[0], $depth + 1);
    }

    private function hasMacro(?string $s): bool
    {
        return $s !== null && str_contains($s, '%{');
    }

    private function addOnce(Issue $issue): void
    {
        foreach ($this->issues as $i) {
            if ($i->code === $issue->code) {
                return;
            }
        }
        $this->issues[] = $issue;
    }
}
