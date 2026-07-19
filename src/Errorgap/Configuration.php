<?php

declare(strict_types=1);

namespace Errorgap;

final class Configuration
{
    /** @var list<string> */
    public const DEFAULT_FILTER_KEYS = [
        'password',
        'password_confirmation',
        'token',
        'secret',
        'api_key',
        'authorization',
        'cookie',
    ];

    public string $endpoint;
    public ?string $projectSlug;
    public ?string $projectId;
    public ?string $apiKey;
    public string $environment;
    public string $rootDirectory;
    public bool $async;
    public ?\Psr\Log\LoggerInterface $logger;
    /** @var list<string> */
    public array $filterKeys;
    public bool $apmEnabled;
    public float $apmSampleRate;
    public int $timeoutSeconds;
    public bool $logsEnabled;
    public string $minimumLogLevel;
    public int $maxBreadcrumbs;

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
     *   apmEnabled?: bool,
     *   apmSampleRate?: float,
     *   timeoutSeconds?: int,
     *   logsEnabled?: bool,
     *   minimumLogLevel?: string,
     *   maxBreadcrumbs?: int,
     * } $options
     */
    public function __construct(array $options = [])
    {
        $this->endpoint = $options['endpoint']
            ?? (string)(getenv('ERRORGAP_ENDPOINT') ?: 'http://127.0.0.1:3030');
        $this->projectSlug = $options['projectSlug']
            ?? (getenv('ERRORGAP_PROJECT_SLUG') ?: null);
        $this->projectId = $options['projectId']
            ?? (getenv('ERRORGAP_PROJECT_ID') ?: null);
        $this->apiKey = $options['apiKey']
            ?? (getenv('ERRORGAP_API_KEY') ?: null);
        $this->environment = $options['environment']
            ?? (string)(getenv('ERRORGAP_ENVIRONMENT') ?: 'production');
        $this->rootDirectory = $options['rootDirectory']
            ?? (string)getcwd();
        $this->async = $options['async'] ?? true;
        $this->logger = array_key_exists('logger', $options) ? $options['logger'] : null;
        $this->filterKeys = $options['filterKeys'] ?? self::DEFAULT_FILTER_KEYS;
        $this->apmEnabled = $options['apmEnabled']
            ?? filter_var(getenv('ERRORGAP_APM_ENABLED') ?: false, FILTER_VALIDATE_BOOL);
        $this->apmSampleRate = max(0.0, min(1.0, (float)($options['apmSampleRate']
            ?? (getenv('ERRORGAP_APM_SAMPLE_RATE') ?: 1.0))));
        $this->timeoutSeconds = $options['timeoutSeconds'] ?? 5;
        $this->logsEnabled = $options['logsEnabled'] ?? true;
        $this->minimumLogLevel = $options['minimumLogLevel']
            ?? (string)(getenv('ERRORGAP_MIN_LOG_LEVEL') ?: 'info');
        $this->maxBreadcrumbs = max(0, $options['maxBreadcrumbs'] ?? 25);
    }

    public function validate(): void
    {
        if ($this->projectSlug === null || trim($this->projectSlug) === '') {
            throw new \RuntimeException('Errorgap projectSlug is required');
        }
    }
}
