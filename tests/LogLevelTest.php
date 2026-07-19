<?php

declare(strict_types=1);

namespace Errorgap\Tests;

use Errorgap\LogLevel;
use PHPUnit\Framework\TestCase;

final class LogLevelTest extends TestCase
{
    public function testNormalizesAliases(): void
    {
        $this->assertSame('warn', LogLevel::normalize('WARNING'));
        $this->assertSame('warn', LogLevel::normalize('warn'));
        $this->assertSame('error', LogLevel::normalize('critical'));
        $this->assertSame('error', LogLevel::normalize('emergency'));
        $this->assertSame('info', LogLevel::normalize('notice'));
    }

    public function testPassesThroughCanonicalAndDefaultsUnknown(): void
    {
        $this->assertSame('fatal', LogLevel::normalize('fatal'));
        $this->assertSame('debug', LogLevel::normalize('debug'));
        $this->assertSame('info', LogLevel::normalize('nonsense'));
    }

    public function testRankOrdersSeverities(): void
    {
        $this->assertLessThan(LogLevel::rank('debug'), LogLevel::rank('trace'));
        $this->assertLessThan(LogLevel::rank('warn'), LogLevel::rank('info'));
        $this->assertLessThan(LogLevel::rank('error'), LogLevel::rank('warn'));
        $this->assertLessThan(LogLevel::rank('fatal'), LogLevel::rank('error'));
    }
}
