<?php

declare(strict_types=1);

namespace Errorgap\Tests;

use Errorgap\Client;
use Errorgap\Configuration;
use Errorgap\Errorgap;
use Errorgap\Filter;
use Errorgap\Notice;
use Errorgap\Release;
use PHPUnit\Framework\TestCase;

final class HardeningTest extends TestCase
{
    protected function tearDown(): void
    {
        Errorgap::init(['captureGlobals' => false]);
        Errorgap::setUser();
        foreach (glob(sys_get_temp_dir() . '/errorgap-pause-*') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function testMasksKeysInsideListsAndTruncates(): void
    {
        $filtered = Filter::params([
            'users' => [['password' => 'x', 'name' => 'Ada']],
            'long' => str_repeat('x', 10000),
            'object' => new \DateTimeImmutable('2026-01-01'),
            'many' => range(1, 500),
        ], ['password']);

        self::assertSame('[FILTERED]', $filtered['users'][0]['password']);
        self::assertSame('Ada', $filtered['users'][0]['name']);
        self::assertStringEndsWith('[TRUNCATED]', $filtered['long']);
        self::assertSame('[DateTimeImmutable]', $filtered['object']);
        self::assertCount(128, $filtered['many']);
    }

    public function testNoticeFiltersSessionAndCarriesRelease(): void
    {
        $config = new Configuration(['projectSlug' => 'demo', 'release' => 'v9']);
        $notice = Notice::fromThrowable(new \RuntimeException('x'), $config, [], [], ['csrf_token' => 't', 'cart' => 1]);

        self::assertSame('v9', $notice['context']['release']);
        self::assertSame(['csrf_token' => '[FILTERED]', 'cart' => 1], $notice['session']);
    }

    public function testBeforeNotifyCanDropOrChangeAndAThrowingHookIsSkipped(): void
    {
        $sent = [];
        $client = static function (callable $hook) use (&$sent): Client {
            return new class (new Configuration(['projectSlug' => 'demo', 'async' => false, 'beforeNotify' => $hook]), $sent) extends Client {
                /** @param array<int, mixed> $sent */
                public function __construct(Configuration $configuration, private array &$sent)
                {
                    parent::__construct($configuration);
                }

                public function deliver(array $notice): \Errorgap\DeliveryResult
                {
                    $this->sent[] = $notice;
                    return new \Errorgap\DeliveryResult(status: 201);
                }
            };
        };

        $dropped = $client(static fn (array $notice) => false)->notify(new \RuntimeException('no'));
        self::assertSame('dropped by beforeNotify', $dropped->body);

        $client(static function (array $notice): array {
            $notice['context']['team'] = 'billing';
            return $notice;
        })->notify(new \RuntimeException('yes'));
        $client(static function (array $notice): array {
            throw new \LogicException('bug in the hook');
        })->notify(new \RuntimeException('still sent'));

        self::assertCount(2, $sent);
        self::assertSame('billing', $sent[0]['context']['team']);
        self::assertSame('still sent', $sent[1]['errors'][0]['message']);
    }

    public function testIgnoredEnvironmentSendsNothing(): void
    {
        $client = new Client(new Configuration([
            'projectSlug' => 'demo', 'async' => false, 'environment' => 'test', 'ignoreEnvironments' => ['test'],
        ]));
        self::assertSame('ignored environment', $client->notify(new \RuntimeException('x'))->body);
    }

    public function testA429PausesSendingForRetryAfter(): void
    {
        $ingestor = new FakeIngestor(429, '{}');
        try {
            $client = new Client(new Configuration([
                'endpoint' => $ingestor->endpoint(), 'projectSlug' => 'pause-demo', 'async' => false,
            ]));
            $pid = pcntl_fork();
            if ($pid === 0) {
                $ingestor->acceptOne();
                exit(0);
            }
            usleep(20_000);
            self::assertSame(429, $client->notify(new \RuntimeException('one'))->status);
            pcntl_waitpid($pid, $status);

            // Nothing is listening now: a second send would fail on the network.
            $second = $client->notify(new \RuntimeException('two'));
            self::assertNotNull($second->error);
            self::assertStringContainsString('rate limited', $second->error->getMessage());
        } finally {
            $ingestor->close();
        }
    }

    public function testFatalErrorsAreReportedOnShutdownButWarningsAndHandledExceptionsAreNot(): void
    {
        Errorgap::init(['projectSlug' => 'demo', 'endpoint' => 'http://127.0.0.1:1', 'timeoutSeconds' => 1]);
        try {
            self::assertNull(Errorgap::reportFatal(null));
            self::assertNull(Errorgap::reportFatal(['type' => E_WARNING, 'message' => 'w', 'file' => 'a.php', 'line' => 1]));
            $result = Errorgap::reportFatal([
                'type' => E_ERROR, 'message' => "Allowed memory size exhausted\n#0 trace", 'file' => 'a.php', 'line' => 3,
            ]);
            // Sent (and refused by the closed port): it was not skipped.
            self::assertNotNull($result);
            self::assertNotNull($result->error);
        } finally {
            restore_error_handler();
            restore_exception_handler();
        }
    }

    public function testSetUserNamesTheUserOnNotices(): void
    {
        $captured = null;
        Errorgap::init([
            'projectSlug' => 'demo', 'captureGlobals' => false, 'async' => false, 'endpoint' => 'http://127.0.0.1:1',
            'timeoutSeconds' => 1,
            'beforeNotify' => static function (array $notice) use (&$captured) {
                $captured = $notice;
                return false;
            },
        ]);
        Errorgap::setUser(42, 'ada@example.com');
        Errorgap::notify(new \RuntimeException('x'), session: ['cart' => 1]);

        self::assertSame(['user_id' => '42', 'user_email' => 'ada@example.com', 'cart' => 1], $captured['session']);
    }

    public function testReleaseIsReadFromTheGitCheckout(): void
    {
        $root = sys_get_temp_dir() . '/errorgap-release-' . bin2hex(random_bytes(4));
        mkdir($root . '/.git/refs/heads', 0777, true);
        file_put_contents($root . '/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($root . '/.git/refs/heads/main', str_repeat('a', 40) . "\n");
        self::assertSame(str_repeat('a', 40), Release::detect($root));
        file_put_contents($root . '/REVISION', "cap123\n");
        self::assertSame('cap123', Release::detect($root));
    }
}
