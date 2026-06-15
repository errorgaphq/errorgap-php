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
     * @param array<string, mixed> $notice
     */
    public function deliver(array $notice): DeliveryResult
    {
        $url = $this->noticesUrl();
        $body = json_encode($notice, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
        $status = $this->extractStatus($http_response_header ?? []);

        if ($response === false && $status === null) {
            $exception = new \RuntimeException('Errorgap delivery failed: network error');
            $this->log($exception);
            return new DeliveryResult(error: $exception);
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
