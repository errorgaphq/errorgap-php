<?php

declare(strict_types=1);

namespace Errorgap;

final class Errorgap
{
    private static ?Configuration $configuration = null;
    private static ?Client $client = null;
    private static ?Breadcrumbs $breadcrumbs = null;
    private static bool $handlersInstalled = false;
    /** @var callable|null */
    private static $previousErrorHandler = null;
    /** @var callable|null */
    private static $previousExceptionHandler = null;

    /**
     * @param array{
     *   endpoint?: string,
     *   projectSlug?: string,
     *   projectId?: string,
     *   apiKey?: string,
     *   environment?: string,
     *   rootDirectory?: string,
     *   async?: bool,
     *   logger?: ?\Psr\Log\LoggerInterface,
     *   filterKeys?: list<string>,
     *   timeoutSeconds?: int,
     *   apmEnabled?: bool,
     *   apmSampleRate?: float,
     *   logsEnabled?: bool,
     *   minimumLogLevel?: string,
     *   maxBreadcrumbs?: int,
     *   captureGlobals?: bool,
     * } $options
     */
    public static function init(array $options = []): void
    {
        $captureGlobals = $options['captureGlobals'] ?? true;
        unset($options['captureGlobals']);

        self::$configuration = new Configuration($options);
        self::$client = new Client(self::$configuration);
        self::$breadcrumbs = new Breadcrumbs(self::$configuration->maxBreadcrumbs);

        if ($captureGlobals) {
            self::installHandlers();
        } else {
            self::uninstallHandlers();
        }
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $environment
     * @param array<string, mixed> $session
     * @param array<string, mixed> $params
     */
    public static function notify(
        \Throwable $exception,
        array $context = [],
        array $environment = [],
        array $session = [],
        array $params = [],
        bool $sync = false,
    ): DeliveryResult {
        $crumbs = self::breadcrumbs()->snapshot();
        if ($crumbs !== [] && !array_key_exists('breadcrumbs', $context)) {
            $context['breadcrumbs'] = $crumbs;
        }
        return self::client()->notify($exception, $context, $environment, $session, $params, $sync);
    }

    /** @param array<string, mixed> $transaction */
    public static function notifyTransaction(array $transaction, bool $sync = false): DeliveryResult
    {
        return self::client()->notifyTransaction($transaction, $sync);
    }

    /** Deliver one structured log line. */
    public static function log(
        string $message,
        string $level = 'info',
        ?string $source = null,
        bool $sync = false,
    ): DeliveryResult {
        return self::client()->notifyLog($message, $level, $source, $sync);
    }

    /**
     * Record a diagnostic breadcrumb attached to subsequent notices.
     *
     * @param array<string, mixed> $metadata
     */
    public static function addBreadcrumb(string $message, ?string $category = null, array $metadata = []): void
    {
        self::breadcrumbs()->add($message, $category, $metadata);
    }

    public static function clearBreadcrumbs(): void
    {
        self::breadcrumbs()->clear();
    }

    /**
     * Time a web interaction and deliver it as an APM transaction. The callback
     * receives a {@see SpanCollector} for recording DB and HTTP spans, and its
     * return value is passed through.
     *
     * @param array<string, mixed> $meta
     */
    public static function trackTransaction(array $meta, callable $operation): mixed
    {
        $collector = new SpanCollector();
        $startedAt = gmdate('Y-m-d\TH:i:s\Z');
        $start = microtime(true);
        try {
            return $operation($collector);
        } finally {
            self::notifyTransaction(array_merge(
                ['kind' => 'web', 'occurred_at' => $startedAt],
                $meta,
                ['duration_ms' => (microtime(true) - $start) * 1000.0, 'spans' => $collector->toArray()],
            ));
        }
    }

    /**
     * Time a background job and deliver it as a `job` transaction.
     */
    public static function trackJob(string $jobClass, callable $operation, string $queue = 'default'): mixed
    {
        $collector = new SpanCollector();
        $startedAt = gmdate('Y-m-d\TH:i:s\Z');
        $start = microtime(true);
        try {
            return $operation($collector);
        } finally {
            self::notifyTransaction([
                'kind' => 'job',
                'job_class' => $jobClass,
                'queue' => $queue,
                'occurred_at' => $startedAt,
                'duration_ms' => (microtime(true) - $start) * 1000.0,
                'spans' => $collector->toArray(),
            ]);
        }
    }

    public static function breadcrumbs(): Breadcrumbs
    {
        if (self::$breadcrumbs === null) {
            self::$breadcrumbs = new Breadcrumbs(self::configuration()->maxBreadcrumbs);
        }
        return self::$breadcrumbs;
    }

    public static function configuration(): Configuration
    {
        if (self::$configuration === null) {
            self::$configuration = new Configuration();
        }
        return self::$configuration;
    }

    public static function client(): Client
    {
        if (self::$client === null) {
            self::$client = new Client(self::configuration());
        }
        return self::$client;
    }

    private static function installHandlers(): void
    {
        if (self::$handlersInstalled) {
            return;
        }
        self::$handlersInstalled = true;

        self::$previousExceptionHandler = set_exception_handler(static function (\Throwable $exception): void {
            self::notify($exception, context: ['source' => 'exception_handler'], sync: true);
            if (self::$previousExceptionHandler !== null) {
                (self::$previousExceptionHandler)($exception);
            }
        });

        self::$previousErrorHandler = set_error_handler(static function (
            int $severity,
            string $message,
            string $file = '',
            int $line = 0,
        ): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }
            $exception = new \ErrorException($message, 0, $severity, $file, $line);
            self::notify($exception, context: ['source' => 'error_handler'], sync: true);
            return self::$previousErrorHandler !== null
                ? (bool)(self::$previousErrorHandler)($severity, $message, $file, $line)
                : false;
        });
    }

    private static function uninstallHandlers(): void
    {
        if (!self::$handlersInstalled) {
            return;
        }
        restore_exception_handler();
        restore_error_handler();
        self::$previousExceptionHandler = null;
        self::$previousErrorHandler = null;
        self::$handlersInstalled = false;
    }
}
