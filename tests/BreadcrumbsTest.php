<?php

declare(strict_types=1);

namespace Errorgap\Tests;

use Errorgap\Breadcrumbs;
use PHPUnit\Framework\TestCase;

final class BreadcrumbsTest extends TestCase
{
    public function testRecordsMessageCategoryAndMetadata(): void
    {
        $buffer = new Breadcrumbs(10);
        $buffer->add('tapped Checkout', 'ui', ['screen' => 'Cart']);
        $crumbs = $buffer->snapshot();
        $this->assertCount(1, $crumbs);
        $this->assertSame('tapped Checkout', $crumbs[0]['message']);
        $this->assertSame('ui', $crumbs[0]['category']);
        $this->assertSame(['screen' => 'Cart'], $crumbs[0]['metadata']);
        $this->assertStringEndsWith('Z', $crumbs[0]['timestamp']);
    }

    public function testDropsOldestBeyondCapacity(): void
    {
        $buffer = new Breadcrumbs(3);
        for ($i = 0; $i < 5; $i++) {
            $buffer->add("event $i");
        }
        $messages = array_column($buffer->snapshot(), 'message');
        $this->assertSame(['event 2', 'event 3', 'event 4'], $messages);
    }

    public function testKeepsNothingWhenCapacityZero(): void
    {
        $buffer = new Breadcrumbs(0);
        $buffer->add('ignored');
        $this->assertSame([], $buffer->snapshot());
    }

    public function testClearEmptiesBuffer(): void
    {
        $buffer = new Breadcrumbs(5);
        $buffer->add('one');
        $buffer->clear();
        $this->assertSame([], $buffer->snapshot());
    }
}
