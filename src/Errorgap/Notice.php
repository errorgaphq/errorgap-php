<?php

declare(strict_types=1);

namespace Errorgap;

final class Notice
{
    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $environment
     * @param array<string, mixed> $session
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function fromThrowable(
        \Throwable $exception,
        Configuration $configuration,
        array $context = [],
        array $environment = [],
        array $session = [],
        array $params = [],
    ): array {
        $defaultContext = [
            'notifier' => 'errorgap-php',
            'notifier_version' => Version::VERSION,
            'environment' => $configuration->environment,
            'root_directory' => $configuration->rootDirectory,
        ];

        return [
            'project_id' => $configuration->projectId,
            'received_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'errors' => [
                [
                    'type' => $exception::class,
                    'message' => $exception->getMessage(),
                    'backtrace' => Backtrace::fromThrowable($exception, $configuration->rootDirectory),
                ],
            ],
            'context' => array_merge($defaultContext, $context),
            'environment' => $environment,
            'session' => $session,
            'params' => Filter::params($params, $configuration->filterKeys),
        ];
    }
}
