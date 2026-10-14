<?php

declare(strict_types=1);

namespace Errorgap\Tests;

use Errorgap\Client;
use Errorgap\Configuration;
use PHPUnit\Framework\TestCase;

final class RemoteSettingsTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (glob(sys_get_temp_dir() . '/errorgap-pause-*') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function testASwitchedOffKindIsNotSentAndTheAnswerIsCached(): void
    {
        $ingestor = new FakeIngestor(200, '{"errors_enabled":false,"apm_enabled":true,"poll_interval_seconds":600}');
        try {
            $client = new Client(new Configuration([
                'endpoint' => $ingestor->endpoint(), 'projectSlug' => 'remote-demo', 'apiKey' => 'key-demo',
                'async' => false, 'remoteSettings' => true, 'timeoutSeconds' => 5,
            ]));
            $pid = pcntl_fork();
            if ($pid === 0) {
                $ingestor->acceptOne();
                exit(0);
            }
            usleep(20_000);

            // The one request the server answers is the settings read; the
            // notice itself is never sent, and neither is a second one.
            $first = $client->notify(new \RuntimeException('one'));
            pcntl_waitpid($pid, $status);
            self::assertSame('switched off for this project', $first->body);
            self::assertSame('switched off for this project', $client->notify(new \RuntimeException('two'))->body);
            self::assertFalse($client->remoteSettings()['errors_enabled']);
        } finally {
            $ingestor->close();
        }
    }

    public function testEverythingStaysOnWhenErrorgapCannotBeReadOrTheOptionIsOff(): void
    {
        $unreachable = new Client(new Configuration([
            'endpoint' => 'http://127.0.0.1:1', 'projectSlug' => 'remote-down', 'async' => false,
            'remoteSettings' => true, 'timeoutSeconds' => 1,
        ]));
        self::assertArrayNotHasKey('errors_enabled', $unreachable->remoteSettings());
        // Sent (and refused by the closed port), not skipped.
        self::assertNotNull($unreachable->notify(new \RuntimeException('x'))->error);

        $off = new Client(new Configuration([
            'endpoint' => 'http://127.0.0.1:1', 'projectSlug' => 'remote-off', 'async' => false,
            'remoteSettings' => false, 'timeoutSeconds' => 1,
        ]));
        self::assertNotNull($off->notify(new \RuntimeException('x'))->error);
    }
}
