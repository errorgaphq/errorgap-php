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
    /** Report sign-ins (`signIn`). Off by default: events carry user names and IPs. */
    public bool $authEvents;
    /** The app's name in Security › Logins; defaults to the project slug. */
    public ?string $appName;
    /** The deployed version or commit, sent as `context.release`. */
    public ?string $release;
    /** @var list<string> Environments in which nothing is sent. */
    public array $ignoreEnvironments;
    /**
     * Sees each notice (an array) before it is sent. Return the notice,
     * changed or not, or `false` to drop it.
     *
     * @var (callable(array<string, mixed>): (array<string, mixed>|false|null))|null
     */
    public $beforeNotify;
    /**
     * Follow the project's settings in errorgap (Project settings › What SDKs
     * send): stop sending what is switched off there.
     */
    public bool $remoteSettings;

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
     *   authEvents?: bool,
     *   appName?: string,
     *   release?: string,
     *   ignoreEnvironments?: list<string>,
     *   beforeNotify?: callable,
     *   remoteSettings?: bool,
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
        $this->authEvents = $options['authEvents'] ?? false;
        $this->appName = $options['appName'] ?? (getenv('ERRORGAP_APP_NAME') ?: null);
        $this->release = $options['release'] ?? Release::detect($this->rootDirectory);
        $this->ignoreEnvironments = $options['ignoreEnvironments'] ?? array_values(array_filter(array_map(
            'trim',
            explode(',', (string)(getenv('ERRORGAP_IGNORE_ENVIRONMENTS') ?: '')),
        ), static fn (string $name): bool => $name !== ''));
        $this->beforeNotify = $options['beforeNotify'] ?? null;
        $this->remoteSettings = $options['remoteSettings']
            ?? (strtolower(trim((string)(getenv('ERRORGAP_REMOTE_SETTINGS') ?: ''))) !== 'false');
    }

    public function ignoredEnvironment(): bool
    {
        return in_array($this->environment, $this->ignoreEnvironments, true);
    }

    public function validate(): void
    {
        if ($this->projectSlug === null || trim($this->projectSlug) === '') {
            throw new \RuntimeException('Errorgap projectSlug is required');
        }
    }
}
