<?php

declare(strict_types=1);

namespace Errorgap\Tests;

use Errorgap\Errorgap;
use Errorgap\TransactionContext;
use PHPUnit\Framework\TestCase;

/**
 * Errors raised inside an APM transaction carry its id, so errorgap shows the
 * error the request actually raised and links the occurrence to its trace.
 */
final class TransactionContextTest extends TestCase
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function testRunMakesAnIdCurrentAndRestoresThePrevious(): void
    {
        $this->assertNull(TransactionContext::current());
        $outer = TransactionContext::run(function (string $id): array {
            $inner = TransactionContext::run(fn (string $nested): string => $nested);
            return [$id, TransactionContext::current(), $inner];
        });
        [$id, $seen, $inner] = $outer;
        $this->assertMatchesRegularExpression(self::UUID, $id);
        $this->assertSame($id, $seen, 'a nested transaction restores the outer one');
        $this->assertNotSame($id, $inner);
        $this->assertNull(TransactionContext::current());
    }

    public function testBeginAndEndPairForEventDrivenWork(): void
    {
        $outer = TransactionContext::begin();
        $inner = TransactionContext::begin();
        $this->assertSame($inner, TransactionContext::current());
        TransactionContext::end();
        $this->assertSame($outer, TransactionContext::current());
        TransactionContext::end();
        $this->assertNull(TransactionContext::current());
        // An unmatched end() is harmless.
        TransactionContext::end();
        $this->assertNull(TransactionContext::current());
    }

    public function testTheIdIsRestoredWhenTheOperationThrows(): void
    {
        try {
            TransactionContext::run(function (): void {
                throw new \RuntimeException('x');
            });
        } catch (\RuntimeException) {
        }
        $this->assertNull(TransactionContext::current());
    }

    public function testANoticeInsideATransactionCarriesItsId(): void
    {
        $ingestor = new FakeIngestor();
        try {
            Errorgap::init([
                'endpoint' => $ingestor->endpoint(),
                'projectSlug' => 'demo',
                'apiKey' => 'egp_test',
                'async' => false,
                'apmEnabled' => false,
                'timeoutSeconds' => 30,
                'captureGlobals' => false,
            ]);

            $pid = pcntl_fork();
            if ($pid === 0) {
                $ingestor->acceptOne();
                exit(0);
            }
            usleep(20_000);

            $id = Errorgap::trackTransaction(['method' => 'GET', 'path' => '/orders/{id}'], function (): ?string {
                Errorgap::notify(new \RuntimeException('boom'), sync: true);
                return Errorgap::currentTransactionId();
            });
            pcntl_waitpid($pid, $status);

            $captured = $ingestor->lastRequest();
            $this->assertNotNull($captured);
            $this->assertMatchesRegularExpression(self::UUID, (string) $id);
            $this->assertSame($id, $captured['body']['context']['transaction_id']);
            $this->assertNull(Errorgap::currentTransactionId());
        } finally {
            $ingestor->close();
        }
    }

    public function testAJobTransactionSendsItsId(): void
    {
        $ingestor = new FakeIngestor();
        try {
            Errorgap::init([
                'endpoint' => $ingestor->endpoint(),
                'projectSlug' => 'demo',
                'apiKey' => 'egp_test',
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

            $id = Errorgap::trackJob('ReceiptJob', fn (): ?string => Errorgap::currentTransactionId());
            pcntl_waitpid($pid, $status);

            $captured = $ingestor->lastRequest();
            $this->assertNotNull($captured);
            $this->assertSame('job', $captured['body']['kind']);
            $this->assertSame($id, $captured['body']['id']);
        } finally {
            $ingestor->close();
        }
    }

    public function testBrowserTraceIdAcceptsOnlyUuids(): void
    {
        $this->assertSame(
            '0192f3c4-7a1b-4c2d-9e3f-0123456789ab',
            TransactionContext::browserTraceId(' 0192F3C4-7A1B-4C2D-9E3F-0123456789AB '),
        );
        $this->assertNull(TransactionContext::browserTraceId('not-a-uuid'));
        $this->assertNull(TransactionContext::browserTraceId('0192f3c4-7a1b-4c2d-9e3f-0123456789ab; drop'));
    }

    public function testTrackTransactionRecordsTheBrowserTraceHeader(): void
    {
        $ingestor = new FakeIngestor();
        $_SERVER['HTTP_X_ERRORGAP_TRACE'] = '0192f3c4-7a1b-4c2d-9e3f-0123456789ab';
        try {
            Errorgap::init([
                'endpoint' => $ingestor->endpoint(),
                'projectSlug' => 'demo',
                'apiKey' => 'egp_test',
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

            $id = Errorgap::trackTransaction(
                ['method' => 'GET', 'path' => '/orders/{id}', 'path_raw' => '/orders/7', 'status_code' => 200],
                fn (): ?string => Errorgap::currentTransactionId(),
            );
            pcntl_waitpid($pid, $status);

            $captured = $ingestor->lastRequest();
            $this->assertNotNull($captured);
            $this->assertSame($id, $captured['body']['id']);
            $this->assertSame('0192f3c4-7a1b-4c2d-9e3f-0123456789ab', $captured['body']['trace_id']);
        } finally {
            unset($_SERVER['HTTP_X_ERRORGAP_TRACE']);
            $ingestor->close();
        }
    }
}
