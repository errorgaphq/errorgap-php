<?php

declare(strict_types=1);

namespace Errorgap\Tests;

use Errorgap\Client;
use Errorgap\Configuration;
use Errorgap\SignIn;
use PHPUnit\Framework\TestCase;

final class SignInTest extends TestCase
{
    public function testReadsTheServerArrayWithoutTheQueryString(): void
    {
        $event = SignIn::build('success', 'mara@oxcoffee.com', server: [
            'REMOTE_ADDR' => '198.51.100.71',
            'HTTP_USER_AGENT' => 'Safari 18',
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/login?next=/admin&token=abc',
        ]);
        $this->assertNotNull($event);
        $this->assertSame('mara@oxcoffee.com', $event['user']);
        $this->assertSame('198.51.100.71', $event['ip']);
        $this->assertSame('Safari 18', $event['user_agent']);
        $this->assertSame('POST /login', $event['path']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $event['occurred_at']);
    }

    public function testReadsAPsr7Request(): void
    {
        $request = new class {
            /** @return array<string, string> */
            public function getServerParams(): array { return ['REMOTE_ADDR' => '203.0.113.9']; }
            public function getHeaderLine(string $name): string { return $name === 'User-Agent' ? 'curl/8' : ''; }
            public function getMethod(): string { return 'POST'; }
            public function getUri(): object { return new class { public function getPath(): string { return '/wp-login.php'; } }; }
        };
        $event = SignIn::build('locked', 'admin', $request);
        $this->assertSame(['203.0.113.9', 'curl/8', 'POST /wp-login.php'], [$event['ip'], $event['user_agent'], $event['path']]);
    }

    public function testDropsUnknownOutcomes(): void
    {
        $this->assertNull(SignIn::build('teleported'));
    }

    public function testPostsToLoginsWebOnceOptedIn(): void
    {
        $ingestor = new FakeIngestor();
        try {
            $base = [
                'endpoint' => $ingestor->endpoint(),
                'projectSlug' => 'ox-coffee',
                'apiKey' => 'k1',
                'environment' => 'production',
                'async' => false,
                'timeoutSeconds' => 30,
            ];
            $this->assertSame(204, (new Client(new Configuration($base)))->signIn('success', 'x')->status);

            $client = new Client(new Configuration($base + ['authEvents' => true, 'appName' => 'oxcoffee-web']));
            $pid = pcntl_fork();
            if ($pid === 0) {
                $ingestor->acceptOne();
                exit(0);
            }
            usleep(20_000);
            $client->signIn('mfa_failure', 'admin', ip: '203.0.113.9', sync: true);
            pcntl_waitpid($pid, $status);

            $captured = $ingestor->lastRequest();
            $this->assertNotNull($captured);
            $this->assertSame('/api/projects/ox-coffee/logins/web', $captured['path']);
            $this->assertSame('k1', $captured['headers']['x-errorgap-project-key'] ?? null);
            $body = $captured['body'];
            $this->assertSame('oxcoffee-web', $body['app']);
            $this->assertSame('production', $body['environment']);
            $this->assertStringStartsWith('errorgap-php ', $body['sdk']);
            $this->assertSame('mfa_failure', $body['events'][0]['outcome']);
            $this->assertSame('203.0.113.9', $body['events'][0]['ip']);
        } finally {
            $ingestor->close();
        }
    }
}
