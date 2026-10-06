<?php

declare(strict_types=1);

namespace Errorgap;

/**
 * Sign-ins to this app, shown beside SSH logins in errorgap's
 * Security › Logins. Only who, from where and the result are sent: never
 * passwords, tokens or session ids.
 */
final class SignIn
{
    /** @var list<string> */
    public const OUTCOMES = ['success', 'failure', 'password_reset', 'mfa_failure', 'locked'];

    /**
     * The event to send, or null for an unknown outcome. The IP, user agent
     * and path come from a PSR-7 request when given, else from `$server`
     * (`$_SERVER`). Paths never include the query string.
     *
     * @param array<string, mixed> $server
     * @return array<string, string>|null
     */
    public static function build(
        string $outcome,
        ?string $user = null,
        ?object $request = null,
        ?string $ip = null,
        ?string $userAgent = null,
        ?string $path = null,
        ?string $method = null,
        array $server = [],
    ): ?array {
        if (!in_array($outcome, self::OUTCOMES, true)) {
            return null;
        }
        if ($request !== null && method_exists($request, 'getServerParams')) {
            /** @var array<string, mixed> $params */
            $params = $request->getServerParams();
            $ip ??= isset($params['REMOTE_ADDR']) ? (string)$params['REMOTE_ADDR'] : null;
            if (method_exists($request, 'getHeaderLine')) {
                $ua = (string)$request->getHeaderLine('User-Agent');
                $userAgent ??= $ua !== '' ? $ua : null;
            }
            if (method_exists($request, 'getMethod') && method_exists($request, 'getUri')) {
                $path ??= $request->getMethod() . ' ' . $request->getUri()->getPath();
            }
        } else {
            $ip ??= isset($server['REMOTE_ADDR']) ? (string)$server['REMOTE_ADDR'] : null;
            $userAgent ??= isset($server['HTTP_USER_AGENT']) ? (string)$server['HTTP_USER_AGENT'] : null;
            if ($path === null && isset($server['REQUEST_METHOD'], $server['REQUEST_URI'])) {
                $path = $server['REQUEST_METHOD'] . ' ' . strtok((string)$server['REQUEST_URI'], '?');
            }
        }

        $event = ['occurred_at' => gmdate('Y-m-d\TH:i:s\Z'), 'outcome' => $outcome];
        $name = $user === null ? '' : trim($user);
        if ($name !== '') {
            $event['user'] = $name;
        }
        if ($ip !== null && $ip !== '') {
            $event['ip'] = $ip;
        }
        if ($userAgent !== null && $userAgent !== '') {
            $event['user_agent'] = substr($userAgent, 0, 512);
        }
        if ($path !== null && trim($path) !== '') {
            $event['path'] = substr($path, 0, 200);
        }
        if ($method !== null && $method !== '') {
            $event['method'] = $method;
        }
        return $event;
    }

    /**
     * @param array<string, string> $event
     * @return array<string, mixed>
     */
    public static function payload(array $event, Configuration $configuration): array
    {
        return [
            'app' => $configuration->appName ?? $configuration->projectSlug,
            'environment' => $configuration->environment,
            'sdk' => 'errorgap-php ' . Version::VERSION,
            'events' => [$event],
        ];
    }
}
