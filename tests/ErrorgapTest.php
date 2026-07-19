<?php

declare(strict_types=1);

namespace Errorgap\Tests;

use Errorgap\Errorgap;
use Errorgap\SpanCollector;
use PHPUnit\Framework\TestCase;

final class ErrorgapTest extends TestCase
{
    public function testNotifyAttachesRecordedBreadcrumbs(): void
    {
        $ingestor = new FakeIngestor();
        try {
            Errorgap::init([
                'endpoint' => $ingestor->endpoint(),
                'projectSlug' => 'demo',
                'apiKey' => 'flk_test',
                'async' => false,
                'timeoutSeconds' => 30,
                'captureGlobals' => false,
            ]);
            Errorgap::clearBreadcrumbs();
            Errorgap::addBreadcrumb('opened Cart', 'navigation');
            Errorgap::addBreadcrumb('tapped Checkout', 'ui', ['orderId' => 'ord-1']);

            $pid = pcntl_fork();
            if ($pid === 0) {
                $ingestor->acceptOne();
                exit(0);
            }
            usleep(20_000);

            Errorgap::notify(new \RuntimeException('boom'), sync: true);
            pcntl_waitpid($pid, $status);

            $captured = $ingestor->lastRequest();
            $this->assertNotNull($captured);
            $crumbs = $captured['body']['context']['breadcrumbs'];
            $this->assertSame(['opened Cart', 'tapped Checkout'], array_column($crumbs, 'message'));
        } finally {
            $ingestor->close();
        }
    }

    public function testTrackJobDeliversJobTransactionWithSpans(): void
    {
        $ingestor = new FakeIngestor();
        try {
            Errorgap::init([
                'endpoint' => $ingestor->endpoint(),
                'projectSlug' => 'demo',
                'apiKey' => 'flk_test',
                'async' => false,
                'apmEnabled' => true,
                'apmSampleRate' => 1.0,
                'timeoutSeconds' => 30,
                'captureGlobals' => false,
            ]);

            $pid = pcntl_fork();
            if ($pid === 0) {
                $ingestor->acceptOne();
                exit(0);
            }
            usleep(20_000);

            $result = Errorgap::trackJob('ReceiptJob', static function (SpanCollector $spans): string {
                $spans->database('SELECT total FROM receipts WHERE id = 7', 3.0, function: 'ReceiptJob::run');
                return 'done';
            }, 'mailers');
            $this->assertSame('done', $result);

            pcntl_waitpid($pid, $status);
            $captured = $ingestor->lastRequest();
            $this->assertNotNull($captured);
            $this->assertSame('/api/projects/demo/transactions', $captured['path']);
            $this->assertSame('job', $captured['body']['kind']);
            $this->assertSame('ReceiptJob', $captured['body']['job_class']);
            $this->assertSame('mailers', $captured['body']['queue']);
            $this->assertCount(1, $captured['body']['spans']);
            $this->assertSame('SELECT total FROM receipts WHERE id = ?', $captured['body']['spans'][0]['sql']);
        } finally {
            $ingestor->close();
        }
    }

    public function testTrackTransactionTimesWebInteraction(): void
    {
        $ingestor = new FakeIngestor();
        try {
            Errorgap::init([
                'endpoint' => $ingestor->endpoint(),
                'projectSlug' => 'demo',
                'apiKey' => 'flk_test',
                'async' => false,
                'apmEnabled' => true,
                'apmSampleRate' => 1.0,
                'timeoutSeconds' => 30,
                'captureGlobals' => false,
            ]);

            $pid = pcntl_fork();
            if ($pid === 0) {
                $ingestor->acceptOne();
                exit(0);
            }
            usleep(20_000);

            Errorgap::trackTransaction(
                ['method' => 'GET', 'path' => '/orders/{orderId}', 'path_raw' => '/orders/7', 'status_code' => 200],
                static function (SpanCollector $spans): void {
                    $spans->external(30.0, function: 'Gateway::fetch');
                },
            );

            pcntl_waitpid($pid, $status);
            $captured = $ingestor->lastRequest();
            $this->assertNotNull($captured);
            $this->assertSame('web', $captured['body']['kind']);
            $this->assertSame('/orders/{orderId}', $captured['body']['path']);
            $this->assertSame('/orders/7', $captured['body']['path_raw']);
            $this->assertIsNumeric($captured['body']['duration_ms']);
            $this->assertCount(1, $captured['body']['spans']);
        } finally {
            $ingestor->close();
        }
    }
}
