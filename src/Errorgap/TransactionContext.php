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

    /** @var list<?string> ids that were current before each begin() */
    private static array $previous = [];

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
        $id = self::begin();
        try {
            return $operation($id);
        } finally {
            self::end();
        }
    }

    /**
     * Start a transaction whose end arrives in a later call — event-driven
     * integrations (a queue's "job processing" and "job processed" events).
     * Returns its id; pair with {@see end()}.
     */
    public static function begin(): string
    {
        self::$previous[] = self::$current;
        self::$current = self::newId();
        return self::$current;
    }

    /** End the transaction {@see begin()} started, restoring the one before it. */
    public static function end(): void
    {
        self::$current = self::$previous === [] ? null : array_pop(self::$previous);
    }

    /**
     * The trace id a browser SDK sent in the `x-errorgap-trace` header,
     * linking its view of an API call to the server transaction that answered
     * it. Defaults to the current request's header; only a well-formed UUID
     * is returned, lowercased.
     */
    public static function browserTraceId(?string $header = null): ?string
    {
        $header ??= isset($_SERVER['HTTP_X_ERRORGAP_TRACE']) && is_string($_SERVER['HTTP_X_ERRORGAP_TRACE'])
            ? $_SERVER['HTTP_X_ERRORGAP_TRACE']
            : null;
        if ($header === null) {
            return null;
        }
        $value = strtolower(trim($header));
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $value) === 1
            ? $value
            : null;
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
