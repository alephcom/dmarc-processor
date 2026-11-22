<?php

namespace App\Alerts;

final class Severity
{
    public const INFO = 'info';

    public const WARNING = 'warning';

    public const CRITICAL = 'critical';

    public const ORDER = [self::INFO => 0, self::WARNING => 1, self::CRITICAL => 2];

    public const OPTIONS = [self::INFO => 'Info', self::WARNING => 'Warning', self::CRITICAL => 'Critical'];

    public static function atLeast(string $severity, string $min): bool
    {
        return (self::ORDER[$severity] ?? 0) >= (self::ORDER[$min] ?? 0);
    }

    public static function color(string $severity): string
    {
        return match ($severity) {
            self::CRITICAL => 'danger',
            self::WARNING => 'warning',
            default => 'info',
        };
    }
}
