<?php

namespace App\Intel;

final class AsnInfo
{
    public function __construct(
        public readonly int $asn,
        public readonly ?string $org,
        public readonly ?string $country = null,
    ) {}
}
