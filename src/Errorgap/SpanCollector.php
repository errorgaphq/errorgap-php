<?php

declare(strict_types=1);

namespace Errorgap;

/** Collects spans recorded while a transaction or job is in flight. */
final class SpanCollector
{
    /** @var list<Span> */
    private array $spans = [];

    public function add(Span $span): void
    {
        $this->spans[] = $span;
    }

    public function database(
        string $sql,
        float $durationMs,
        ?string $file = null,
        ?int $line = null,
        ?string $function = null,
    ): void {
        $this->add(Span::database($sql, $durationMs, $file, $line, $function));
    }

    public function external(
        float $durationMs,
        ?string $file = null,
        ?int $line = null,
        ?string $function = null,
    ): void {
        $this->add(Span::external($durationMs, $file, $line, $function));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn (Span $span): array => $span->toArray(), $this->spans);
    }
}
