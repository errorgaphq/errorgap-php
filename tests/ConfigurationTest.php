<?php

declare(strict_types=1);

namespace Errorgap\Tests;

use Errorgap\Configuration;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase
{
    private array $originalEnv = [];

    protected function setUp(): void
    {
        foreach (['ERRORGAP_ENDPOINT', 'ERRORGAP_PROJECT_SLUG', 'ERRORGAP_PROJECT_ID', 'ERRORGAP_API_KEY'] as $key) {
            $this->originalEnv[$key] = getenv($key);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("$key=$value");
            }
        }
    }

    public function testDefaultsWhenNothingProvided(): void
    {
        $config = new Configuration();
        $this->assertSame('http://127.0.0.1:3030', $config->endpoint);
        $this->assertTrue($config->async);
        $this->assertContains('password', $config->filterKeys);
        $this->assertContains('authorization', $config->filterKeys);
    }

    public function testReadsEnvironmentVariables(): void
    {
        putenv('ERRORGAP_ENDPOINT=https://errorgap.example.com');
        putenv('ERRORGAP_PROJECT_SLUG=demo');
        putenv('ERRORGAP_PROJECT_ID=p_123');
        putenv('ERRORGAP_API_KEY=flk_test');
        $config = new Configuration();
        $this->assertSame('https://errorgap.example.com', $config->endpoint);
        $this->assertSame('demo', $config->projectSlug);
        $this->assertSame('p_123', $config->projectId);
        $this->assertSame('flk_test', $config->apiKey);
    }

    public function testExplicitOptionsOverrideEnv(): void
    {
        putenv('ERRORGAP_PROJECT_SLUG=from-env');
        $config = new Configuration(['projectSlug' => 'from-arg']);
        $this->assertSame('from-arg', $config->projectSlug);
    }

    public function testValidateThrowsWhenProjectSlugMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/projectSlug/');
        (new Configuration())->validate();
    }

    public function testValidatePassesWhenProjectSlugPresent(): void
    {
        (new Configuration(['projectSlug' => 'demo']))->validate();
        $this->expectNotToPerformAssertions();
    }
}
