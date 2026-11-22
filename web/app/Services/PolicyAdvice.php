<?php

namespace App\Services;

final class PolicyAdvice
{
    /**
     * @param  string  $status  setup | done | blocked | wait | ready
     * @param  array{0: string, 1: int}|null  $next
     * @param  list<array{0: bool, 1: string}>  $checks
     */
    public function __construct(
        public readonly string $status,
        public readonly string $current,
        public readonly ?array $next,
        public readonly string $headline,
        public readonly string $explanation,
        public readonly array $checks = [],
        public readonly ?string $record = null,
    ) {}
}
