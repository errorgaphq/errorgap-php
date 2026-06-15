<?php

declare(strict_types=1);

namespace Errorgap\Tests;

use Errorgap\Filter;
use PHPUnit\Framework\TestCase;

final class FilterTest extends TestCase
{
    private const DEFAULTS = ['password', 'token', 'secret', 'api_key', 'authorization', 'cookie'];

    public function testMasksFilteredKeys(): void
    {
        $out = Filter::params(
            ['username' => 'alice', 'password' => 'hunter2', 'access_token' => 'x'],
            self::DEFAULTS,
        );
        $this->assertSame('alice', $out['username']);
        $this->assertSame('[FILTERED]', $out['password']);
        $this->assertSame('[FILTERED]', $out['access_token']);
    }

    public function testRecursesIntoNestedAssoc(): void
    {
        $out = Filter::params(
            ['user' => ['name' => 'alice', 'api_key' => 'x']],
            self::DEFAULTS,
        );
        $this->assertSame('alice', $out['user']['name']);
        $this->assertSame('[FILTERED]', $out['user']['api_key']);
    }

    public function testCaseInsensitiveMatch(): void
    {
        $out = Filter::params(['Authorization' => 'Bearer xyz'], self::DEFAULTS);
        $this->assertSame('[FILTERED]', $out['Authorization']);
    }

    public function testListsUntouched(): void
    {
        $out = Filter::params(['items' => [1, 2, 3]], self::DEFAULTS);
        $this->assertSame([1, 2, 3], $out['items']);
    }
}
