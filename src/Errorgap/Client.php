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
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
        $status = $this->extractStatus($http_response_header);

        if ($response === false && $status === null) {
            $exception = new \RuntimeException('Errorgap delivery failed: network error');
            $this->log($exception);
            return new DeliveryResult(error: $exception);
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
