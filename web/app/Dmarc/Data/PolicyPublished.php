<?php

namespace App\Dmarc\Data;

final class PolicyPublished
{
    public function __construct(
        public readonly string $domain,
        public readonly string $p,
        public readonly ?string $sp = null,
        public readonly ?string $np = null,
        public readonly string $adkim = 'r',
        public readonly string $aspf = 'r',
        public readonly int $pct = 100,
        public readonly ?string $fo = null,
    ) {}

    /** @return array<string, string|int|null> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
