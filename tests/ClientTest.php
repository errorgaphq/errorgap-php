<?php

declare(strict_types=1);

namespace Errorgap\Tests;

use Errorgap\Client;
use Errorgap\Configuration;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    public function testPostsToNoticesWithCanonicalHeaders(): void
    {
        $ingestor = new FakeIngestor();
        try {
            $config = new Configuration([
                'endpoint' => $ingestor->endpoint(),
                'projectSlug' => 'demo',
                'apiKey' => 'flk_test',
                'async' => false,
            ]);
            $client = new Client($config);

            // Need to accept the connection in a worker because file_get_contents
            // blocks. Use a child PHP process via stream_select pattern, OR pre-fork.
            // For simplicity: spawn a background "thread" using a forked listener.
            // Since PHP doesn't have threads in CLI mode, we run accept in a
            // non-blocking fashion by issuing the HTTP call first then accepting.
            // Trick: use stream_socket_pair and an alarm? Easier — spawn a
            // background process to accept the connection.
            $pid = pcntl_fork();
            if ($pid === 0) {
                $ingestor->acceptOne();
                exit(0);
            }
            usleep(20_000); // give child a head start

            $exc = new \RuntimeException('test');
            $result = $client->notify($exc, sync: true);
            $this->assertSame(201, $result->status);

            pcntl_waitpid($pid, $status);

            $captured = $ingestor->lastRequest();
            $this->assertNotNull($captured);
            $this->assertSame('POST', $captured['method']);
            $this->assertSame('/api/projects/demo/notices', $captured['path']);
            $this->assertSame('application/json', $captured['headers']['content-type'] ?? null);
            $this->assertSame('flk_test', $captured['headers']['x-errorgap-project-key'] ?? null);
            $this->assertStringStartsWith('errorgap-php/', $captured['headers']['user-agent'] ?? '');
        } finally {
            $ingestor->close();
        }
    }

    public function testSendsFullNoticeEnvelope(): void
    {
        $ingestor = new FakeIngestor();
        try {
            $config = new Configuration([
                'endpoint' => $ingestor->endpoint(),
                'projectSlug' => 'demo',
                'apiKey' => 'flk_test',
                'async' => false,
            ]);
            $client = new Client($config);

            $pid = pcntl_fork();
            if ($pid === 0) {
                $ingestor->acceptOne();
                exit(0);
            }
            usleep(20_000);

            $client->notify(new \TypeError('kaboom'), sync: true);
            pcntl_waitpid($pid, $status);

            $captured = $ingestor->lastRequest();
            $this->assertNotNull($captured);
            $body = $captured['body'];
            $this->assertIsArray($body);
            $this->assertArrayHasKey('errors', $body);
            $this->assertArrayHasKey('context', $body);
            $this->assertSame('TypeError', $body['errors'][0]['type']);
            $this->assertSame('kaboom', $body['errors'][0]['message']);
            $this->assertSame('errorgap-php', $body['context']['notifier']);
        } finally {
            $ingestor->close();
        }
    }

    public function testReturnsErrorResultWhenProjectSlugMissing(): void
    {
        $ingestor = new FakeIngestor();
        try {
            $config = new Configuration([
                'endpoint' => $ingestor->endpoint(),
                'apiKey' => 'flk_test',
            ]);
            $client = new Client($config);
            $result = $client->notify(new \RuntimeException('x'), sync: true);
            $this->assertNotNull($result->error);
        } finally {
            $ingestor->close();
        }
    }
}
