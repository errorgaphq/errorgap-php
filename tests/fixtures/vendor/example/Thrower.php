<?php

declare(strict_types=1);

namespace Errorgap\Tests\Fixtures\Vendor;

function throwVendorException(): \Throwable
{
    try {
        throw new \RuntimeException('vendor failure');
    } catch (\Throwable $exception) {
        return $exception;
    }
}
