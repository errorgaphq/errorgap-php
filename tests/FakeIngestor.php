<?php

declare(strict_types=1);

namespace Errorgap\Tests;

/**
 * In-process fake ingestor. Each test instance binds a fresh TCP socket
 * and serves a single POST request synchronously.
 */
final class FakeIngestor
{
    /** @var resource */
    private $socket;
    private int $port;
    private string $captureFile;

    public int $responseStatus;
    public string $responseBody;

    public function __construct(int $responseStatus = 201, string $responseBody = '{"group_id":"g_1"}')
    {
        $this->responseStatus = $responseStatus;
        $this->responseBody = $responseBody;
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            throw new \RuntimeException("Failed to bind ingestor: $errstr");
        }
        $this->socket = $sock;
        $name = stream_socket_get_name($sock, false);
        if ($name === false) {
            throw new \RuntimeException('Failed to read socket name');
        }
        $parts = explode(':', $name);
        $this->port = (int)end($parts);
        $tmp = tempnam(sys_get_temp_dir(), 'errorgap-ingestor-');
        if ($tmp === false) {
            throw new \RuntimeException('Failed to create capture file');
        }
        $this->captureFile = $tmp;
    }

    /**
     * Read the most recent captured request from the shared file.
     *
     * @return array{path: string, method: string, headers: array<string, string>, body: mixed}|null
     */
    public function lastRequest(): ?array
    {
        if (!is_file($this->captureFile)) {
            return null;
        }
        $raw = file_get_contents($this->captureFile);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function endpoint(): string
    {
        return sprintf('http://127.0.0.1:%d', $this->port);
    }

    public function port(): int
    {
        return $this->port;
    }

    /**
     * Accept one connection, parse the request, send the response.
     * Returns once the request has been served.
     */
    public function acceptOne(int $timeoutSeconds = 5): void
    {
        $client = @stream_socket_accept($this->socket, $timeoutSeconds);
        if ($client === false) {
            throw new \RuntimeException('Ingestor accept timed out');
        }

        $request = '';
        $headerEnd = false;
        $contentLength = 0;

        // Read headers.
        while (!feof($client)) {
            $chunk = fread($client, 1024);
            if ($chunk === false) {
                break;
            }
            $request .= $chunk;
            if (str_contains($request, "\r\n\r\n")) {
                $headerEnd = true;
                break;
            }
        }

        if (!$headerEnd) {
            fclose($client);
            return;
        }

        [$headerBlob, $body] = explode("\r\n\r\n", $request, 2);
        $headerLines = explode("\r\n", $headerBlob);
        $startLine = array_shift($headerLines);
        if (!is_string($startLine)) {
            fclose($client);
            return;
        }
        $startParts = explode(' ', $startLine);
        $method = $startParts[0] ?? '';
        $path = $startParts[1] ?? '';

        $headers = [];
        foreach ($headerLines as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }
        $contentLength = isset($headers['content-length']) ? (int)$headers['content-length'] : 0;

        while (strlen($body) < $contentLength && !feof($client)) {
            $chunk = fread($client, 4096);
            if ($chunk === false) {
                break;
            }
            $body .= $chunk;
        }

        $decoded = json_decode($body, true);
        file_put_contents(
            $this->captureFile,
            json_encode([
                'path' => $path,
                'method' => $method,
                'headers' => $headers,
                'body' => $decoded ?? $body,
            ], JSON_UNESCAPED_SLASHES),
        );

        $response = sprintf(
            "HTTP/1.1 %d OK\r\nContent-Type: application/json\r\nContent-Length: %d\r\nConnection: close\r\n\r\n%s",
            $this->responseStatus,
            strlen($this->responseBody),
            $this->responseBody,
        );
        fwrite($client, $response);
        fclose($client);
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        if (is_file($this->captureFile)) {
            @unlink($this->captureFile);
        }
    }
}
