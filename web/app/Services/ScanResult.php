<?php

namespace App\Services;

use App\Dns\Checks\CheckResult;
use App\Dns\Checks\Issue;

final class ScanResult
{
    public function __construct(
        public readonly CheckResult $dmarc,
        public readonly CheckResult $spf,
        public readonly CheckResult $dkim,
        public readonly CheckResult $reportAuth,
        public readonly ?string $provider,
        public readonly string $suggestedDmarc,
    ) {}

    /** The DMARC record is live, valid and sends reports to us. */
    public function verified(): bool
    {
        if (! ($this->dmarc->parsed['has_our_rua'] ?? false)) {
            return false;
        }
        foreach ($this->dmarc->issues as $i) {
            if ($i->severity === Issue::ERROR) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, CheckResult> */
    public function all(): array
    {
        return ['dmarc' => $this->dmarc, 'spf' => $this->spf, 'dkim' => $this->dkim, 'rua_auth' => $this->reportAuth];
    }
}
