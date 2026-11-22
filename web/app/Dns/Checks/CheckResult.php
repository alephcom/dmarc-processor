<?php

namespace App\Dns\Checks;

final class CheckResult
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const ERROR = 'error';

    public const MISSING = 'missing';

    public const UNKNOWN = 'unknown'; // lookup failed; try again

    /**
     * @param  list<Issue>  $issues
     * @param  array<string, mixed>  $parsed
     */
    public function __construct(
        public readonly string $type,
        public readonly string $status,
        public readonly ?string $raw = null,
        public readonly array $parsed = [],
        public readonly array $issues = [],
    ) {}

    /** Derive status from issues: any error => error, any warning => warn, else ok. */
    public static function fromIssues(string $type, ?string $raw, array $parsed, array $issues): self
    {
        $severities = array_map(fn (Issue $i) => $i->severity, $issues);
        $status = match (true) {
            in_array(Issue::ERROR, $severities, true) => self::ERROR,
            in_array(Issue::WARNING, $severities, true) => self::WARN,
            default => self::OK,
        };

        return new self($type, $status, $raw, $parsed, $issues);
    }

    public static function lookupFailed(string $type, string $message): self
    {
        return new self($type, self::UNKNOWN, null, [], [
            Issue::warning('lookup_failed', 'We couldn\'t complete the DNS lookup: '.$message, 'This is usually temporary. Try again in a minute.'),
        ]);
    }

    public function hasIssue(string $code): bool
    {
        foreach ($this->issues as $i) {
            if ($i->code === $code) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function issueCodes(): array
    {
        return array_map(fn (Issue $i) => $i->code, $this->issues);
    }
}
