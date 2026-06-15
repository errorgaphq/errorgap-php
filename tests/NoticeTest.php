<?php

declare(strict_types=1);

namespace Errorgap\Tests;

use Errorgap\Configuration;
use Errorgap\Notice;
use Errorgap\Version;
use PHPUnit\Framework\TestCase;

final class NoticeTest extends TestCase
{
    public function testCapturesTypeAndMessage(): void
    {
        $config = new Configuration(['projectSlug' => 'demo']);
        $exc = new \TypeError('boom');
        $notice = Notice::fromThrowable($exc, $config);
        $this->assertSame(\TypeError::class, $notice['errors'][0]['type']);
        $this->assertSame('boom', $notice['errors'][0]['message']);
    }

    public function testIncludesNotifierIdentification(): void
    {
        $config = new Configuration(['projectSlug' => 'demo', 'environment' => 'test']);
        $notice = Notice::fromThrowable(new \RuntimeException('x'), $config);
        $this->assertSame('errorgap-php', $notice['context']['notifier']);
        $this->assertSame(Version::VERSION, $notice['context']['notifier_version']);
        $this->assertSame('test', $notice['context']['environment']);
    }

    public function testFiltersSensitiveParams(): void
    {
        $config = new Configuration(['projectSlug' => 'demo']);
        $notice = Notice::fromThrowable(
            new \RuntimeException('x'),
            $config,
            params: [
                'username' => 'alice',
                'password' => 'hunter2',
                'nested' => ['auth_token' => 'abc', 'safe' => 'ok'],
            ],
        );
        $this->assertSame('alice', $notice['params']['username']);
        $this->assertSame('[FILTERED]', $notice['params']['password']);
        $this->assertSame('[FILTERED]', $notice['params']['nested']['auth_token']);
        $this->assertSame('ok', $notice['params']['nested']['safe']);
    }

    public function testMergesCustomContextOverDefaults(): void
    {
        $config = new Configuration(['projectSlug' => 'demo']);
        $notice = Notice::fromThrowable(
            new \RuntimeException('x'),
            $config,
            context: ['component' => 'billing'],
        );
        $this->assertSame('billing', $notice['context']['component']);
        $this->assertSame('errorgap-php', $notice['context']['notifier']);
    }

    public function testReceivedAtEndsWithZ(): void
    {
        $config = new Configuration(['projectSlug' => 'demo']);
        $notice = Notice::fromThrowable(new \RuntimeException('x'), $config);
        $this->assertStringEndsWith('Z', $notice['received_at']);
    }
}
