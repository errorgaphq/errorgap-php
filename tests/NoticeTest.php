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

    public function testRecordsNestedCausesAndMergesFrames(): void
    {
        $config = new Configuration(['projectSlug' => 'demo', 'rootDirectory' => dirname(__DIR__)]);
        $root = new \RuntimeException('db connection refused');
        $mid = new \LogicException('failed to load order', 0, $root);
        $top = new \DomainException('checkout failed', 0, $mid);

        $notice = Notice::fromThrowable($top, $config);

        $this->assertSame(\DomainException::class, $notice['errors'][0]['type']);
        $this->assertSame('checkout failed', $notice['errors'][0]['message']);
        $this->assertSame([
            ['type' => \LogicException::class, 'message' => 'failed to load order'],
            ['type' => \RuntimeException::class, 'message' => 'db connection refused'],
        ], $notice['context']['causes']);

        // Frames from all three exceptions are merged and contiguously indexed.
        $frames = $notice['errors'][0]['backtrace'];
        $this->assertNotEmpty($frames);
        foreach ($frames as $i => $frame) {
            $this->assertSame($i, $frame['index']);
        }
    }

    public function testOmitsCausesWhenNoPrevious(): void
    {
        $config = new Configuration(['projectSlug' => 'demo']);
        $notice = Notice::fromThrowable(new \RuntimeException('solo'), $config);
        $this->assertArrayNotHasKey('causes', $notice['context']);
    }
}
