<?php

declare(strict_types=1);

namespace Errorgap\Tests;

use Errorgap\Backtrace;
use PHPUnit\Framework\TestCase;

final class BacktraceTest extends TestCase
{
    public function testFromThrowableProducesFrames(): void
    {
        $throwLine = __LINE__ + 2;
        try {
            throw new \RuntimeException('boom');
        } catch (\Throwable $exc) {
            $frames = Backtrace::fromThrowable($exc, dirname(__DIR__));
        }

        $this->assertNotEmpty($frames);
        $top = $frames[0];
        $this->assertSame(0, $top['index']);
        $this->assertSame(__FILE__, dirname(__DIR__) . DIRECTORY_SEPARATOR . $top['file']);
        $this->assertSame($throwLine, $top['line']);
        $this->assertArrayHasKey('source', $top);
        $this->assertContains("            throw new \\RuntimeException('boom');", $top['source']['lines']);
    }

    public function testFormatsClassAndMethod(): void
    {
        $exc = $this->throwFromMethod();
        $frames = Backtrace::fromThrowable($exc, dirname(__DIR__));
        $top = $frames[0];
        $this->assertStringContainsString('throwFromMethod', $top['function'] ?? '');
        $this->assertArrayHasKey('source', $top);
        $this->assertContains("            throw new \\RuntimeException('x');", $top['source']['lines']);
    }

    public function testVendorFramesAlsoIncludeSource(): void
    {
        require_once __DIR__ . '/fixtures/vendor/example/Thrower.php';
        $exc = \Errorgap\Tests\Fixtures\Vendor\throwVendorException();
        $frames = Backtrace::fromThrowable($exc, dirname(__DIR__));

        $vendorFrame = $frames[0];
        $this->assertFalse($vendorFrame['in_app']);
        $this->assertArrayHasKey('source', $vendorFrame);
        $this->assertContains("        throw new \\RuntimeException('vendor failure');", $vendorFrame['source']['lines']);
        $this->assertNotContains(null, array_column($frames, 'file'));
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
