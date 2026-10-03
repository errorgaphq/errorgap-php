<?php

declare(strict_types=1);

namespace Errorgap;

/**
 * The APM transaction currently running, so errors reported during it carry
 * its id (`context.transaction_id`) and errorgap links the error to the
 * request that raised it.
 *
 * PHP serves one request per process (or, under Octane/RoadRunner, one at a
 * time per worker), so the current id is process state set for the duration
 * of {@see run()} and restored afterwards.
 */
final class TransactionContext
{
    private static ?string $current = null;

    public static function current(): ?string
    {
        return self::$current;
    }

    /**
     * Run $operation as one transaction: a new id is current while it runs
     * and is passed to it. The previous id is restored afterwards.
     *
     * @template T
     * @param callable(string): T $operation
     * @return T
     */
    public static function run(callable $operation): mixed
    {
        $previous = self::$current;
        $id = self::newId();
        self::$current = $id;
        try {
            return $operation($id);
        } finally {
            self::$current = $previous;
        }
    }

    /** A random (version 4) UUID in canonical lowercase form. */
    public static function newId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
