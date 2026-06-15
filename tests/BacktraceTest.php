<?php

declare(strict_types=1);

namespace Errorgap\Tests;

use Errorgap\Backtrace;
use PHPUnit\Framework\TestCase;

final class BacktraceTest extends TestCase
{
    public function testFromThrowableProducesFrames(): void
    {
        try {
            throw new \RuntimeException('boom');
        } catch (\Throwable $exc) {
            $frames = Backtrace::fromThrowable($exc, dirname(__DIR__));
        }

        $this->assertNotEmpty($frames);
        $top = $frames[0];
        $this->assertSame(0, $top['index']);
        $this->assertNotNull($top['function']);
    }

    public function testFormatsClassAndMethod(): void
    {
        $exc = $this->throwFromMethod();
        $frames = Backtrace::fromThrowable($exc, dirname(__DIR__));
        $top = $frames[0];
        $this->assertStringContainsString('throwFromMethod', $top['function'] ?? '');
    }

    private function throwFromMethod(): \Throwable
    {
        try {
            throw new \RuntimeException('x');
        } catch (\Throwable $exc) {
            return $exc;
        }
    }
}
