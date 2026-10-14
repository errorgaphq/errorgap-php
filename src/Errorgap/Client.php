<?php

declare(strict_types=1);

namespace Errorgap;

class Client
{
    public function __construct(private Configuration $configuration)
    {
    }

    public function configure(Configuration $configuration): void
    {
        $this->configuration = $configuration;
    }

    public function configuration(): Configuration
    {
        return $this->configuration;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $environment
     * @param array<string, mixed> $session
     * @param array<string, mixed> $params
     */
    public function notify(
        \Throwable $exception,
        array $context = [],
        array $environment = [],
        array $session = [],
        array $params = [],
        bool $sync = false,
    ): DeliveryResult {
        // The request or job this error was raised in, so errorgap links the two.
        $transactionId = TransactionContext::current();
        if ($transactionId !== null && !array_key_exists('transaction_id', $context)) {
            $context['transaction_id'] = $transactionId;
        }
        try {
            $this->configuration->validate();
            $notice = Notice::fromThrowable(
                $exception,
                $this->configuration,
                $context,
                $environment,
                $session,
                $params,
            );
            if ($this->configuration->ignoredEnvironment()) {
                return new DeliveryResult(status: 202, body: 'ignored environment');
            }
            // A hook that throws is logged and skipped: a bug in a filter
            // must not lose the error it was filtering.
            if ($this->configuration->beforeNotify !== null) {
                try {
                    $result = ($this->configuration->beforeNotify)($notice);
                    if ($result === false) {
                        return new DeliveryResult(status: 202, body: 'dropped by beforeNotify');
                    }
                    if (is_array($result) && isset($result['errors'])) {
                        $notice = $result;
                    }
                } catch (\Throwable $hookError) {
                    $this->log($hookError);
                }
            }
        } catch (\Throwable $caught) {
            $this->log($caught);
            return new DeliveryResult(error: $caught);
        }

        if ($sync || !$this->configuration->async) {
            return $this->deliver($notice);
        }

        // Plain PHP has no first-class background worker. Schedule with
        // register_shutdown_function so the request response goes out first
        // and the notice is delivered before the worker dies.
        register_shutdown_function(function () use ($notice): void {
            $this->deliver($notice);
        });

        return new DeliveryResult(status: 202, queued: true);
    }

    /**
     * Deliver one APM web or job transaction.
     *
     * @param array<string, mixed> $transaction
     */
    public function notifyTransaction(array $transaction, bool $sync = false): DeliveryResult
    {
        if (!$this->configuration->apmEnabled || !$this->shouldSampleApm()) {
            return new DeliveryResult(status: 204);
        }

        try {
            $this->configuration->validate();
            $payload = array_merge([
                'environment' => $this->configuration->environment,
                'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'spans' => [],
            ], $transaction);
        } catch (\Throwable $caught) {
            $this->log($caught);
            return new DeliveryResult(error: $caught);
        }

        if ($sync || !$this->configuration->async) {
            return $this->deliverTransaction($payload);
        }

        register_shutdown_function(function () use ($payload): void {
            $this->deliverTransaction($payload);
        });

        return new DeliveryResult(status: 202, queued: true);
    }

    /**
     * Deliver one structured log line.
     */
    public function notifyLog(
        string $message,
        string $level = 'info',
        ?string $source = null,
        bool $sync = false,
    ): DeliveryResult {
        try {
            $this->configuration->validate();
        } catch (\Throwable $caught) {
            $this->log($caught);
            return new DeliveryResult(error: $caught);
        }

        $normalized = LogLevel::normalize($level);
        $threshold = LogLevel::rank(LogLevel::normalize($this->configuration->minimumLogLevel));
        if (!$this->configuration->logsEnabled || LogLevel::rank($normalized) < $threshold) {
            return new DeliveryResult(status: 204);
        }

        $payload = [
            'message' => $message,
            'level' => $normalized,
            'environment' => $this->configuration->environment,
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        if ($source !== null && $source !== '') {
            $payload['source'] = $source;
        }

        if ($sync || !$this->configuration->async) {
            return $this->deliverPayload($this->logsUrl(), $payload);
        }

        register_shutdown_function(function () use ($payload): void {
            $this->deliverPayload($this->logsUrl(), $payload);
        });

        return new DeliveryResult(status: 202, queued: true);
    }

    /**
     * Report a sign-in to this app (Security › Logins). Dropped unless
     * `authEvents` is on, or when the outcome is unknown.
     */
    public function signIn(
        string $outcome,
        ?string $user = null,
        ?object $request = null,
        ?string $ip = null,
        ?string $userAgent = null,
        ?string $path = null,
        ?string $method = null,
        bool $sync = false,
    ): DeliveryResult {
        try {
            $this->configuration->validate();
        } catch (\Throwable $caught) {
            $this->log($caught);
            return new DeliveryResult(error: $caught);
        }
        if (!$this->configuration->authEvents) {
            return new DeliveryResult(status: 204);
        }
        $event = SignIn::build($outcome, $user, $request, $ip, $userAgent, $path, $method, $_SERVER);
        if ($event === null) {
            $this->log(new \InvalidArgumentException(sprintf('unknown sign-in outcome "%s"', $outcome)));
            return new DeliveryResult(status: 204);
        }
        $payload = SignIn::payload($event, $this->configuration);
        $url = sprintf(
            '%s/api/projects/%s/logins/web',
            rtrim($this->configuration->endpoint, '/'),
            $this->configuration->projectSlug ?? '',
        );

        if ($sync || !$this->configuration->async) {
            return $this->deliverPayload($url, $payload);
        }

        register_shutdown_function(function () use ($url, $payload): void {
            $this->deliverPayload($url, $payload);
        });

        return new DeliveryResult(status: 202, queued: true);
    }

    /**
     * @param array<string, mixed> $notice
     */
    public function deliver(array $notice): DeliveryResult
    {
        return $this->deliverPayload($this->noticesUrl(), $notice);
    }

    /**
     * @param array<string, mixed> $transaction
     */
    public function deliverTransaction(array $transaction): DeliveryResult
    {
        return $this->deliverPayload($this->transactionsUrl(), $transaction);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function deliverPayload(string $url, array $payload): DeliveryResult
    {
        if ($this->configuration->ignoredEnvironment()) {
            return new DeliveryResult(status: 204);
        }
        if ($this->rateLimited()) {
            return new DeliveryResult(error: new \RuntimeException('Errorgap is rate limited, not sent'));
        }
        if (!$this->remotelyEnabled($url)) {
            return new DeliveryResult(status: 204, body: 'switched off for this project');
        }

        // Invalid UTF-8 (binary params) is replaced rather than losing the
        // whole payload.
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );
        if ($body === false) {
            $exception = new \RuntimeException('Failed to JSON-encode notice');
            $this->log($exception);
            return new DeliveryResult(error: $exception);
        }

        $headers = [
            'Content-Type: application/json',
            'User-Agent: errorgap-php/' . Version::VERSION,
        ];
        if ($this->configuration->apiKey !== null && $this->configuration->apiKey !== '') {
            $headers[] = 'X-Errorgap-Project-Key: ' . $this->configuration->apiKey;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => $this->configuration->timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        // Unset when the connection itself failed (refused, DNS, timeout).
        /** @var list<string> $responseHeaders */
        $responseHeaders = $http_response_header ?? []; // @phpstan-ignore-line
        $status = $this->extractStatus($responseHeaders);

        if ($response === false && $status === null) {
            $exception = new \RuntimeException('Errorgap delivery failed: network error');
            $this->log($exception);
            return new DeliveryResult(error: $exception);
        }

        if ($status === 429 || $status === 503) {
            $this->pauseFor($this->retryAfter($responseHeaders));
        }

        if ($status !== null && $status >= 400) {
            $this->log(new \RuntimeException(sprintf(
                'Errorgap delivery failed: HTTP %d%s',
                $status,
                $response === false || $response === '' ? '' : ' ' . $response,
            )));
        }

        return new DeliveryResult(
            status: $status,
            body: $response === false ? null : $response,
        );
    }

    private function noticesUrl(): string
    {
        $base = rtrim($this->configuration->endpoint, '/');
        return sprintf('%s/api/projects/%s/notices', $base, $this->configuration->projectSlug ?? '');
    }

    private function transactionsUrl(): string
    {
        $base = rtrim($this->configuration->endpoint, '/');
        return sprintf('%s/api/projects/%s/transactions', $base, $this->configuration->projectSlug ?? '');
    }

    private function logsUrl(): string
    {
        $base = rtrim($this->configuration->endpoint, '/');
        return sprintf('%s/api/projects/%s/logs', $base, $this->configuration->projectSlug ?? '');
    }

    /**
     * A 429 or 503 pauses sending for the server's `Retry-After`. PHP
     * requests share nothing, so the pause is a timestamp in a temp file
     * that every worker of this app checks before sending.
     */
    private function rateLimited(): bool
    {
        $file = $this->pauseFile();
        if (!@is_file($file)) {
            return false;
        }
        $until = (int)@file_get_contents($file);
        if ($until > time()) {
            return true;
        }
        @unlink($file);
        return false;
    }

    private function pauseFor(int $seconds): void
    {
        $seconds = max(1, min(300, $seconds));
        @file_put_contents($this->pauseFile(), (string)(time() + $seconds), LOCK_EX);
        $this->log(new \RuntimeException(sprintf('rate limited, pausing delivery for %ds', $seconds)));
    }

    /**
     * The project's remote settings (Project settings › What SDKs send in
     * errorgap): false only when the kind of data `$url` carries has been
     * switched off there. PHP requests share nothing, so the settings are
     * kept in a temp file and re-read from errorgap when they are older than
     * the poll interval. If errorgap cannot be reached, everything stays on.
     */
    private function remotelyEnabled(string $url): bool
    {
        if (!$this->configuration->remoteSettings) {
            return true;
        }
        $field = match (true) {
            str_ends_with($url, '/notices') => 'errors_enabled',
            str_ends_with($url, '/transactions') => 'apm_enabled',
            str_ends_with($url, '/logs') => 'logs_enabled',
            default => null,
        };
        if ($field === null) {
            return true;
        }
        $settings = $this->remoteSettings();
        return ($settings[$field] ?? true) !== false;
    }

    /**
     * @return array<string, mixed>
     */
    public function remoteSettings(bool $refresh = false): array
    {
        $file = $this->pauseFile() . '-settings';
        $cached = @is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
        $cached = is_array($cached) ? $cached : [];
        $interval = max(60, (int)($cached['poll_interval_seconds'] ?? 600));
        if (!$refresh && $cached !== [] && (int)($cached['_fetched_at'] ?? 0) + $interval > time()) {
            return $cached;
        }

        $headers = ['User-Agent: errorgap-php/' . Version::VERSION];
        if ($this->configuration->apiKey !== null && $this->configuration->apiKey !== '') {
            $headers[] = 'X-Errorgap-Project-Key: ' . $this->configuration->apiKey;
        }
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            // Short: this runs in a request, at most once per poll interval.
            'timeout' => min(2, $this->configuration->timeoutSeconds),
            'ignore_errors' => true,
        ]]);
        $base = rtrim($this->configuration->endpoint, '/');
        $body = @file_get_contents(
            sprintf('%s/api/projects/%s/config', $base, $this->configuration->projectSlug ?? ''),
            false,
            $context,
        );
        /** @var list<string> $responseHeaders */
        $responseHeaders = $http_response_header ?? []; // @phpstan-ignore-line
        $fresh = $this->extractStatus($responseHeaders) === 200 && is_string($body) ? json_decode($body, true) : null;
        if (!is_array($fresh)) {
            // Unreadable: keep what we had, and do not ask again for a minute.
            $fresh = $cached;
            $fresh['poll_interval_seconds'] = $cached['poll_interval_seconds'] ?? 600;
            $fresh['_fetched_at'] = time() - max(60, (int)$fresh['poll_interval_seconds']) + 60;
        } else {
            $fresh['_fetched_at'] = time();
        }
        @file_put_contents($file, (string)json_encode($fresh), LOCK_EX);
        return $fresh;
    }

    private function pauseFile(): string
    {
        $key = md5($this->configuration->endpoint . '|' . ($this->configuration->projectSlug ?? ''));
        return rtrim(sys_get_temp_dir(), '/\\') . '/errorgap-pause-' . $key;
    }

    /**
     * @param list<string> $responseHeaders
     */
    private function retryAfter(array $responseHeaders): int
    {
        foreach ($responseHeaders as $line) {
            if (preg_match('/^retry-after:\s*(\d+)\s*$/i', $line, $matches) === 1) {
                return (int)$matches[1];
            }
        }
        return 60;
    }

    private function shouldSampleApm(): bool
    {
        $rate = $this->configuration->apmSampleRate;
        if ($rate >= 1.0) {
            return true;
        }
        if ($rate <= 0.0) {
            return false;
        }

        return mt_rand() / mt_getrandmax() < $rate;
    }

    /**
     * @param list<string> $responseHeaders
     */
    private function extractStatus(array $responseHeaders): ?int
    {
        foreach ($responseHeaders as $line) {
            if (preg_match('#^HTTP/[\d.]+\s+(\d+)#', $line, $matches) === 1) {
                return (int) $matches[1];
            }
        }
        return null;
    }

    private function log(\Throwable $exception): void
    {
        $logger = $this->configuration->logger;
        if ($logger === null) {
            return;
        }
        $logger->warning(sprintf('[errorgap] %s: %s', $exception::class, $exception->getMessage()));
    }
}
