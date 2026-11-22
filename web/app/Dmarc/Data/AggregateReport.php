<?php

namespace App\Dmarc\Data;

use Carbon\CarbonImmutable;

final class AggregateReport
{
    /**
     * @param  list<string>  $errors
     * @param  list<ReportRecord>  $records
     */
    public function __construct(
        public readonly string $orgName,
        public readonly ?string $email,
        public readonly string $reportId,
        public readonly CarbonImmutable $dateBegin,
        public readonly CarbonImmutable $dateEnd,
        public readonly PolicyPublished $policy,
        public readonly array $records,
        public readonly array $errors = [],
        public readonly ?string $extraContact = null,
    ) {}

    public function totalMessages(): int
    {
        return array_sum(array_map(fn (ReportRecord $r) => $r->count, $this->records));
    }
}
