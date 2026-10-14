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

        if ($configuration->release !== null && $configuration->release !== '') {
            $defaultContext['release'] = $configuration->release;
        }

        $causes = self::causes($exception);
        if ($causes !== []) {
            $defaultContext['causes'] = $causes;
        }

        return [
            'project_id' => $configuration->projectId,
            'received_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'errors' => [
                [
                    'type' => $exception::class,
                    'message' => Filter::string($exception->getMessage(), 8192),
                    'backtrace' => Backtrace::chain($exception, $configuration->rootDirectory),
                ],
            ],
            'context' => array_merge($defaultContext, Filter::clean($context)),
            'environment' => Filter::clean($environment),
            'session' => Filter::params($session, $configuration->filterKeys),
            'params' => Filter::params($params, $configuration->filterKeys),
        ];
    }

    /**
     * Type and message of each wrapped `getPrevious()` exception, nearest first.
     *
     * @return list<array{type: string, message: string}>
     */
    private static function causes(\Throwable $exception): array
    {
        $causes = [];
        $current = $exception->getPrevious();
        $depth = 0;
        while ($current !== null && $depth < 10) {
            $causes[] = ['type' => $current::class, 'message' => $current->getMessage()];
            $current = $current->getPrevious();
            $depth++;
        }
        return $causes;
    }
}
