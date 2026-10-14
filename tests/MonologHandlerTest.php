<?php

declare(strict_types=1);

namespace Errorgap\Tests;

use Errorgap\Errorgap;
use Errorgap\Monolog\Handler;
use Errorgap\Monolog\LoggedError;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class MonologHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        Errorgap::init(['captureGlobals' => false]);
    }

    public function testReportsErrorRecordsWithOrWithoutAnException(): void
    {
        $notices = [];
        Errorgap::init([
            'projectSlug' => 'demo',
            'captureGlobals' => false,
            'async' => false,
            'beforeNotify' => static function (array $notice) use (&$notices) {
                $notices[] = $notice;
                return false; // captured here, not sent
            },
        ]);
        $logger = new Logger('billing', [new Handler()]);

        $logger->warning('below the level: not reported');
        $logger->error('could not charge {order}', [
            'exception' => new \DomainException('card declined'),
            'order' => 7,
            'card_token' => 'tok_secret',
        ]);
        $logger->critical('ledger out of balance', ['ledger' => 'eu']);

        self::assertCount(2, $notices);
        self::assertSame(\DomainException::class, $notices[0]['errors'][0]['type']);
        self::assertSame('could not charge {order}', $notices[0]['context']['log_message']);
        self::assertSame('billing', $notices[0]['context']['component']);
        self::assertSame(['order' => 7, 'card_token' => '[FILTERED]'], $notices[0]['params']);

        self::assertSame(LoggedError::class, $notices[1]['errors'][0]['type']);
        self::assertSame('ledger out of balance', $notices[1]['errors'][0]['message']);
        self::assertSame('critical', $notices[1]['context']['severity']);
    }
}
