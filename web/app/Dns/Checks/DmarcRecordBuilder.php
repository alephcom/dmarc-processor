<?php

namespace App\Dns\Checks;

/**
 * Produces the DMARC record a customer should publish: their existing record (if any) with our
 * reporting address merged in, optionally with a new policy/pct for policy progression.
 */
class DmarcRecordBuilder
{
    private const ORDER = ['v', 'p', 'sp', 'np', 'pct', 'rua', 'ruf', 'adkim', 'aspf', 'fo', 'rf', 'ri', 't', 'psd'];

    public function build(?DmarcRecord $existing, string $ruaAddress, ?string $policy = null, ?int $pct = null): string
    {
        $tags = $existing?->tags ?? [];
        $tags['v'] = 'DMARC1';

        if ($policy !== null) {
            $tags['p'] = $policy;
        } elseif ($existing?->policy() === null) {
            $tags['p'] = 'none'; // missing or invalid p: start safely in monitoring mode
        } else {
            $tags['p'] = $existing->policy();
        }

        if ($pct !== null) {
            if ($pct >= 100) {
                unset($tags['pct']);
            } else {
                $tags['pct'] = (string) max(0, $pct);
            }
        }

        $ours = 'mailto:'.strtolower($ruaAddress);
        $uris = DmarcRecord::splitUris($tags['rua'] ?? null);
        $present = false;
        foreach ($uris as $u) {
            if (preg_match('/^mailto:([^!]+)/i', $u, $m) && strtolower(rawurldecode($m[1])) === strtolower($ruaAddress)) {
                $present = true;
            }
        }
        if (! $present) {
            $uris[] = $ours;
        }
        $tags['rua'] = implode(',', $uris);

        // Canonical order: known tags first in RFC order, then anything else as it was.
        $ordered = [];
        foreach (self::ORDER as $k) {
            if (isset($tags[$k])) {
                $ordered[$k] = $tags[$k];
            }
        }
        foreach ($tags as $k => $v) {
            $ordered[$k] ??= $v;
        }

        return (new DmarcRecord($ordered))->toString();
    }
}
