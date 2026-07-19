<?php

declare(strict_types=1);

namespace Errorgap;

/**
 * One APM span (a database query or outbound HTTP call) recorded while a
 * transaction or job is in flight.
 */
final class Span
{
    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(private array $payload)
    {
    }

    public static function database(
        string $sql,
        float $durationMs,
        ?string $file = null,
        ?int $line = null,
        ?string $function = null,
    ): self {
        return new self(self::build('db', $durationMs, self::normalizeSql($sql), $file, $line, $function));
    }

    public static function external(
        float $durationMs,
        ?string $file = null,
        ?int $line = null,
        ?string $function = null,
    ): self {
        return new self(self::build('http', $durationMs, null, $file, $line, $function));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }

    /**
     * @return array<string, mixed>
     */
    private static function build(
        string $kind,
        float $durationMs,
        ?string $sql,
        ?string $file,
        ?int $line,
        ?string $function,
    ): array {
        $payload = ['kind' => $kind, 'duration_ms' => $durationMs];
        if ($sql !== null) {
            $payload['sql'] = $sql;
        }
        if ($file !== null) {
            $payload['file'] = $file;
        }
        if ($line !== null) {
            $payload['line'] = $line;
        }
        if ($function !== null) {
            $payload['fn_name'] = $function;
        }
        return $payload;
    }

    /** Strip literals so query shapes aggregate: '…' and numbers become ?. */
    public static function normalizeSql(string $sql): string
    {
        $sql = (string) preg_replace("/'(?:''|[^'])*'/", '?', $sql);
        $sql = (string) preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $sql);
        $sql = (string) preg_replace('/\s+/', ' ', $sql);
        return trim($sql);
    }
}
