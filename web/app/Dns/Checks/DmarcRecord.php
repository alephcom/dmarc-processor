<?php

namespace App\Dns\Checks;

/**
 * A parsed DMARC TXT record (RFC 7489 §6.3, plus DMARCbis "np", "t" and "psd").
 */
final class DmarcRecord
{
    public const KNOWN_TAGS = ['v', 'p', 'sp', 'np', 'pct', 'rua', 'ruf', 'adkim', 'aspf', 'fo', 'rf', 'ri', 't', 'psd'];

    /** @param array<string, string> $tags in the order they appeared; keys lower-cased */
    public function __construct(public readonly array $tags) {}

    public static function looksLikeDmarc(string $txt): bool
    {
        return (bool) preg_match('/^\s*v\s*=\s*DMARC1\s*(;|$)/i', $txt);
    }

    public static function parse(string $txt): self
    {
        $tags = [];
        foreach (explode(';', $txt) as $part) {
            $part = trim($part);
            if ($part === '' || ! str_contains($part, '=')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode('=', $part, 2));
            $k = strtolower($k);
            if (! array_key_exists($k, $tags)) { // first occurrence wins
                $tags[$k] = $v;
            }
        }

        return new self($tags);
    }

    public function get(string $tag): ?string
    {
        return $this->tags[$tag] ?? null;
    }

    public function policy(): ?string
    {
        $p = strtolower($this->get('p') ?? '');

        return in_array($p, ['none', 'quarantine', 'reject'], true) ? $p : null;
    }

    public function pct(): int
    {
        $pct = $this->get('pct');

        return $pct !== null && ctype_digit($pct) ? min(100, (int) $pct) : 100;
    }

    /** @return list<string> URIs as written, e.g. "mailto:a@b.com!10m" */
    public function ruaUris(): array
    {
        return self::splitUris($this->get('rua'));
    }

    /** @return list<string> lower-cased addresses from mailto: URIs (size suffix removed) */
    public function ruaAddresses(): array
    {
        $out = [];
        foreach ($this->ruaUris() as $uri) {
            if (preg_match('/^mailto:([^!]+)/i', $uri, $m)) {
                $out[] = strtolower(rawurldecode($m[1]));
            }
        }

        return $out;
    }

    /** @return list<string> */
    public static function splitUris(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), fn ($u) => $u !== ''));
    }

    public function toString(): string
    {
        $parts = [];
        foreach ($this->tags as $k => $v) {
            $parts[] = $k.'='.$v;
        }

        return implode('; ', $parts);
    }
}
