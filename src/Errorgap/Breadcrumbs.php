<?php

declare(strict_types=1);

namespace Errorgap;

/**
 * Fixed-size ring of recent app events (navigation, queries, requests) attached
 * to every notice as `context.breadcrumbs` so errors carry the trail that led
 * up to them.
 */
final class Breadcrumbs
{
    /** @var list<array<string, mixed>> */
    private array $crumbs = [];

    public function __construct(private int $capacity = 25)
    {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function add(string $message, ?string $category = null, array $metadata = []): void
    {
        if ($this->capacity <= 0) {
            return;
        }

        $crumb = ['message' => $message, 'timestamp' => gmdate('Y-m-d\TH:i:s\Z')];
        if ($category !== null) {
            $crumb['category'] = $category;
        }
        if ($metadata !== []) {
            $crumb['metadata'] = $metadata;
        }

        $this->crumbs[] = $crumb;
        if (count($this->crumbs) > $this->capacity) {
            $this->crumbs = array_slice($this->crumbs, -$this->capacity);
        }
    }

    public function clear(): void
    {
        $this->crumbs = [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function snapshot(): array
    {
        return $this->crumbs;
    }
}
