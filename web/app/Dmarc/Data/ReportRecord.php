<?php

namespace App\Dmarc\Data;

final class ReportRecord
{
    /**
     * @param  list<array{type: string, comment: ?string}>  $reasons
     * @param  list<array{domain: string, selector: ?string, result: string}>  $dkimResults
     * @param  list<array{domain: string, scope: ?string, result: string}>  $spfResults
     */
    public function __construct(
        public readonly string $sourceIp,
        public readonly int $count,
        public readonly string $disposition,   // none | quarantine | reject
        public readonly bool $dkimAligned,      // policy_evaluated/dkim == pass
        public readonly bool $spfAligned,       // policy_evaluated/spf == pass
        public readonly string $headerFrom,
        public readonly ?string $envelopeFrom = null,
        public readonly ?string $envelopeTo = null,
        public readonly array $reasons = [],
        public readonly array $dkimResults = [],
        public readonly array $spfResults = [],
    ) {}

    public function dmarcPass(): bool
    {
        return $this->dkimAligned || $this->spfAligned;
    }
}
