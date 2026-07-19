<?php

declare(strict_types=1);

namespace Errorgap;

/**
 * Canonicalizes log levels to the six the ingestion API recognizes and orders
 * them so a minimum-level threshold can be applied client-side.
 */
final class LogLevel
{
    public static function normalize(string $level): string
    {
        return match (strtolower(trim($level))) {
            'warning', 'warn' => 'warn',
            'err', 'severe', 'critical', 'alert', 'emergency' => 'error',
            'notice' => 'info',
            'trace', 'debug', 'info', 'error', 'fatal' => strtolower(trim($level)),
            default => 'info',
        };
    }

    public static function rank(string $level): int
    {
        return match ($level) {
            'trace' => 0,
            'debug' => 10,
            'info' => 20,
            'warn' => 30,
            'error' => 40,
            'fatal' => 50,
            default => 20,
        };
    }
}
