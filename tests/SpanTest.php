<?php

declare(strict_types=1);

namespace Errorgap\Tests;

use Errorgap\Span;
use Errorgap\SpanCollector;
use PHPUnit\Framework\TestCase;

final class SpanTest extends TestCase
{
    public function testNormalizeSqlReplacesLiterals(): void
    {
        $this->assertSame(
            'SELECT * FROM orders WHERE id = ? AND name = ?',
            Span::normalizeSql("SELECT * FROM orders WHERE id = 42 AND name = 'alice'"),
        );
    }

    public function testNormalizeSqlCollapsesWhitespace(): void
    {
        $this->assertSame('SELECT ? FROM t', Span::normalizeSql("SELECT\n  1\n  FROM   t"));
    }

    public function testDatabaseSpanShape(): void
    {
        $span = Span::database('SELECT * FROM t WHERE id = 7', 12.5, 'OrderRepo.php', 20, 'OrderRepo::load')->toArray();
        $this->assertSame('db', $span['kind']);
        $this->assertSame('SELECT * FROM t WHERE id = ?', $span['sql']);
        $this->assertSame(12.5, $span['duration_ms']);
        $this->assertSame('OrderRepo.php', $span['file']);
        $this->assertSame(20, $span['line']);
        $this->assertSame('OrderRepo::load', $span['fn_name']);
    }

    public function testExternalSpanShape(): void
    {
        $span = Span::external(88.0, function: 'Gateway::charge')->toArray();
        $this->assertSame('http', $span['kind']);
        $this->assertSame(88.0, $span['duration_ms']);
        $this->assertArrayNotHasKey('sql', $span);
        $this->assertSame('Gateway::charge', $span['fn_name']);
    }

    public function testCollectorAggregatesSpans(): void
    {
        $collector = new SpanCollector();
        $collector->database('SELECT 1', 3.0, function: 'Repo::q');
        $collector->external(50.0, function: 'Api::call');
        $spans = $collector->toArray();
        $this->assertCount(2, $spans);
        $this->assertSame('db', $spans[0]['kind']);
        $this->assertSame('http', $spans[1]['kind']);
    }
}
