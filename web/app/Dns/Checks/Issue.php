<?php

namespace App\Dns\Checks;

final class Issue
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    public const INFO = 'info';

    public function __construct(
        public readonly string $severity,
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $fix = null,
    ) {}

    public static function error(string $code, string $message, ?string $fix = null): self
    {
        return new self(self::ERROR, $code, $message, $fix);
    }

    public static function warning(string $code, string $message, ?string $fix = null): self
    {
        return new self(self::WARNING, $code, $message, $fix);
    }

    public static function info(string $code, string $message, ?string $fix = null): self
    {
        return new self(self::INFO, $code, $message, $fix);
    }

    /** @return array{severity: string, code: string, message: string, fix: ?string} */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array{severity: string, code: string, message: string, fix?: ?string} $a */
    public static function fromArray(array $a): self
    {
        return new self($a['severity'], $a['code'], $a['message'], $a['fix'] ?? null);
    }
}
