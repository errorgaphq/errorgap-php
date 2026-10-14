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
    /** @var array<string, string> */
    private static array $user = [];
    private static bool $shutdownRegistered = false;
    private static bool $uncaughtReported = false;
    /** @var array<string, true> Errors already reported in this request. */
    private static array $seenErrors = [];
    private const FATAL_ERRORS = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
    /** Warnings and notices reported per request before the rest are skipped. */
    private const MAX_ERRORS_PER_REQUEST = 20;
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
     *   authEvents?: bool,
     *   appName?: string,
     *   release?: string,
     *   ignoreEnvironments?: list<string>,
     *   beforeNotify?: callable,
     *   remoteSettings?: bool,
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
        $session = array_merge(self::$user, $session);
        return self::client()->notify($exception, $context, $environment, $session, $params, $sync);
    }

    /** The id of the APM transaction running now, if any. */
    /**
     * Name the user this request is acting for. Later notices carry it as
     * `session.user_id` / `session.user_email`. Call with no arguments to
     * clear it.
     */
    public static function setUser(string|int|null $id = null, ?string $email = null): void
    {
        self::$user = [];
        if ($id !== null && (string)$id !== '') {
            self::$user['user_id'] = (string)$id;
        }
        if ($email !== null && $email !== '') {
            self::$user['user_email'] = $email;
        }
    }

    public static function currentTransactionId(): ?string
    {
        return TransactionContext::current();
    }

    /** @param array<string, mixed> $transaction */
    public static function notifyTransaction(array $transaction, bool $sync = false): DeliveryResult
    {
        return self::client()->notifyTransaction($transaction, $sync);
    }

    /**
     * Report a sign-in to this app: "success", "failure", "password_reset",
     * "mfa_failure" or "locked". The IP, user agent and path come from a
     * PSR-7 `$request` when given, else from `$_SERVER`. Needs
     * `authEvents: true`. Never pass passwords or tokens.
     */
    public static function signIn(
        string $outcome,
        ?string $user = null,
        ?object $request = null,
        ?string $ip = null,
        ?string $userAgent = null,
        ?string $path = null,
        ?string $method = null,
        bool $sync = false,
    ): DeliveryResult {
        return self::client()->signIn($outcome, $user, $request, $ip, $userAgent, $path, $method, $sync);
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
        // Errors reported while the operation runs carry this transaction's id.
        return TransactionContext::run(static function (string $id) use ($meta, $operation): mixed {
            $collector = new SpanCollector();
            $startedAt = gmdate('Y-m-d\TH:i:s\Z');
            $start = microtime(true);
            try {
                return $operation($collector);
            } finally {
                self::notifyTransaction(array_merge(
                    array_filter(
                        ['id' => $id, 'trace_id' => TransactionContext::browserTraceId(), 'kind' => 'web', 'occurred_at' => $startedAt],
                        static fn ($value): bool => $value !== null,
                    ),
                    $meta,
                    ['duration_ms' => (microtime(true) - $start) * 1000.0, 'spans' => $collector->toArray()],
                ));
            }
        });
    }

    /**
     * Time a background job and deliver it as a `job` transaction.
     */
    public static function trackJob(string $jobClass, callable $operation, string $queue = 'default'): mixed
    {
        return TransactionContext::run(static function (string $id) use ($jobClass, $operation, $queue): mixed {
            $collector = new SpanCollector();
            $startedAt = gmdate('Y-m-d\TH:i:s\Z');
            $start = microtime(true);
            try {
                return $operation($collector);
            } finally {
                self::notifyTransaction([
                    'id' => $id,
                    'kind' => 'job',
                    'job_class' => $jobClass,
                    'queue' => $queue,
                    'occurred_at' => $startedAt,
                    'duration_ms' => (microtime(true) - $start) * 1000.0,
                    'spans' => $collector->toArray(),
                ]);
            }
        });
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
            self::$uncaughtReported = true;
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
            // The same warning from a loop is reported once, and a request
            // reports a bounded number: each is delivered when the request
            // ends, so it never waits on errorgap.
            $key = $severity . '|' . $file . '|' . $line . '|' . $message;
            if (!isset(self::$seenErrors[$key]) && count(self::$seenErrors) < self::MAX_ERRORS_PER_REQUEST) {
                self::$seenErrors[$key] = true;
                $exception = new \ErrorException($message, 0, $severity, $file, $line);
                self::notify($exception, context: ['source' => 'error_handler']);
            }
            return self::$previousErrorHandler !== null
                ? (bool)(self::$previousErrorHandler)($severity, $message, $file, $line)
                : false;
        });

        // Fatal errors (out of memory, a missing class, a parse error in an
        // included file, the time limit) never reach either handler above.
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(static function (): void {
                self::reportFatal(error_get_last());
            });
        }
    }

    /**
     * Report `error_get_last()` when it is a fatal error that ended the
     * request. Public for testing.
     *
     * @param array{type: int, message: string, file: string, line: int}|null $error
     */
    public static function reportFatal(?array $error): ?DeliveryResult
    {
        if (!self::$handlersInstalled || self::$uncaughtReported || $error === null) {
            return null;
        }
        if (($error['type'] & self::FATAL_ERRORS) === 0) {
            return null;
        }
        // "Uncaught Exception: …" with the trace appended: keep the first line.
        $message = explode("\n", $error['message'])[0];
        $exception = new \ErrorException($message, 0, $error['type'], $error['file'], $error['line']);
        return self::notify($exception, context: ['source' => 'shutdown', 'fatal' => true], sync: true);
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
        self::$uncaughtReported = false;
        self::$seenErrors = [];
    }
}
