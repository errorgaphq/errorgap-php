<?php

declare(strict_types=1);

namespace Errorgap;

final class DeliveryResult
{
    public function __construct(
        public readonly ?int $status = null,
        public readonly ?string $body = null,
        public readonly ?\Throwable $error = null,
        public readonly bool $queued = false,
    ) {
    }

    public function success(): bool
    {
        return $this->error === null && $this->status !== null && $this->status >= 200 && $this->status < 300;
    }
}
