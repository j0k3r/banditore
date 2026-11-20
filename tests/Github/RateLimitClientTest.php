<?php

namespace App\Tests\Github;

use App\Github\RateLimitClient;
use Github\Client;
use Github\HttpClient\Builder;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Http\Adapter\Guzzle7\Client as Guzzle7Client;
use Http\Client\Exception\NetworkException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

class RateLimitClientTest extends TestCase
{
    public function testReadsFreshCoreHeadersWithoutChangingResponse(): void
    {
        $request = new Request('GET', 'https://api.github.com/repos/owner/repo/tags');
        $response = $this->response();
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->once())->method('sendRequest')->with($request)->willReturn($response);
        $client = new RateLimitClient($httpClient, new MockClock('2026-11-20T13:00:00Z'));

        $this->assertNull($client->getRecentRemaining());
        $this->assertSame($response, $client->sendRequest($request));
        $this->assertSame(5000, $client->getRecentRemaining());
        $client->clear();
        $this->assertNull($client->getRecentRemaining());
    }

    public static function headerCases(): iterable
    {
        yield 'exhausted' => [['X-RateLimit-Remaining' => '0'], 0];
        yield 'near exhaustion' => [['X-RateLimit-Remaining' => '199'], null];
        yield 'safety threshold' => [['X-RateLimit-Remaining' => '200'], 200];
        yield 'missing count' => [['X-RateLimit-Remaining' => ''], null];
        yield 'negative count' => [['X-RateLimit-Remaining' => '-1'], null];
        yield 'non-integer count' => [['X-RateLimit-Remaining' => '1.5'], null];
        yield 'overflow count' => [['X-RateLimit-Remaining' => '9999999999999999999999'], null];
        yield 'missing reset' => [['X-RateLimit-Reset' => ''], null];
        yield 'invalid reset' => [['X-RateLimit-Reset' => 'tomorrow'], null];
        yield 'overflow reset' => [['X-RateLimit-Reset' => '9999999999999999999999'], null];
        yield 'past reset' => [['X-RateLimit-Reset' => '1'], null];
        yield 'reset now' => [['X-RateLimit-Reset' => (string) strtotime('2026-11-20T13:00:00Z')], null];
        yield 'missing resource' => [['X-RateLimit-Resource' => ''], null];
        yield 'different resource' => [['X-RateLimit-Resource' => 'search'], null];
    }

    #[DataProvider('headerCases')]
    public function testUnsafeHeadersRequireExplicitCheck(array $headers, ?int $expected): void
    {
        $httpClient = $this->createStub(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->response(headers: $headers));
        $client = new RateLimitClient($httpClient, new MockClock('2026-11-20T13:00:00Z'));
        $client->sendRequest(new Request('GET', 'https://api.github.com/repos/owner/repo/tags'));

        $this->assertSame($expected, $client->getRecentRemaining());
    }

    public function testReuseCannotExtendTheThirtySecondWindow(): void
    {
        $clock = new MockClock('2026-11-20T13:00:00Z');
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->once())->method('sendRequest')->willReturn($this->response());
        $client = new RateLimitClient($httpClient, $clock);
        $client->sendRequest(new Request('GET', 'https://api.github.com/repos/owner/repo/tags'));

        $clock->sleep(29);
        $this->assertSame(5000, $client->getRecentRemaining());
        $clock->sleep(1);
        $this->assertNull($client->getRecentRemaining());
    }

    public function testResetEndsTheWindowBeforeThirtySeconds(): void
    {
        $clock = new MockClock('2026-11-20T13:00:00Z');
        $httpClient = $this->createStub(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->response(headers: ['X-RateLimit-Reset' => (string) ($clock->now()->getTimestamp() + 5)]));
        $client = new RateLimitClient($httpClient, $clock);
        $client->sendRequest(new Request('GET', 'https://api.github.com/repos/owner/repo/tags'));

        $clock->sleep(5);
        $this->assertNull($client->getRecentRemaining());
    }

    public function testSlowResponsesDoNotExtendTheWindow(): void
    {
        $clock = new MockClock('2026-11-20T13:00:00Z');
        $httpClient = $this->createStub(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturnCallback(function () use ($clock): ResponseInterface {
            $clock->sleep(31);

            return $this->response();
        });
        $client = new RateLimitClient($httpClient, $clock);
        $client->sendRequest(new Request('GET', 'https://api.github.com/repos/owner/repo/tags'));

        $this->assertNull($client->getRecentRemaining());
    }

    public function testMissingHeadersDiscardEarlierQuota(): void
    {
        $httpClient = $this->createStub(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->response(), new Response(500));
        $client = new RateLimitClient($httpClient, new MockClock('2026-11-20T13:00:00Z'));
        $request = new Request('GET', 'https://api.github.com/repos/owner/repo/tags');
        $client->sendRequest($request);
        $this->assertSame(5000, $client->getRecentRemaining());
        $client->sendRequest($request);
        $this->assertNull($client->getRecentRemaining());
    }

    public function testNetworkFailureDiscardsEarlierQuotaAndStillPropagates(): void
    {
        $request = new Request('GET', 'https://api.github.com/repos/owner/repo/tags');
        $failure = new NetworkException('Network failure', $request);
        $calls = 0;
        $httpClient = $this->createStub(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturnCallback(function () use (&$calls, $failure): ResponseInterface {
            if (0 === $calls++) {
                return $this->response();
            }

            throw $failure;
        });
        $client = new RateLimitClient($httpClient, new MockClock('2026-11-20T13:00:00Z'));
        $client->sendRequest($request);
        $this->assertSame(5000, $client->getRecentRemaining());

        try {
            $client->sendRequest($request);
            $this->fail('The network exception must propagate.');
        } catch (NetworkException $e) {
            $this->assertSame($failure, $e);
        }
        $this->assertNull($client->getRecentRemaining());
    }

    public function test304HeadersAreObservedBeforeTheCacheRestoresOldHeaders(): void
    {
        $clock = new MockClock('2026-11-20T13:00:00Z');
        $responses = new MockHandler([
            $this->response(headers: ['ETag' => '"tags-v1"', 'Cache-Control' => 'max-age=0']),
            $this->response(0, status: 304),
        ]);
        $trackedClient = new RateLimitClient(new Guzzle7Client(new \GuzzleHttp\Client(['handler' => $responses])), $clock);
        $client = new Client(new Builder($trackedClient));
        $client->addCache(new ArrayAdapter());

        $client->getHttpClient()->get('/repos/owner/repo/tags');
        $this->assertSame(5000, $trackedClient->getRecentRemaining());
        $clock->sleep(31);
        $client->getHttpClient()->get('/repos/owner/repo/tags');

        $this->assertSame('5000', $client->getLastResponse()?->getHeaderLine('X-RateLimit-Remaining'));
        $this->assertSame(0, $trackedClient->getRecentRemaining());
        $this->assertCount(0, $responses);
    }

    public function testLocalCacheHitsDoNotRefreshQuota(): void
    {
        $clock = new MockClock('2026-11-20T13:00:00Z');
        $responses = new MockHandler([$this->response(headers: ['Cache-Control' => 'max-age=3600'])]);
        $trackedClient = new RateLimitClient(new Guzzle7Client(new \GuzzleHttp\Client(['handler' => $responses])), $clock);
        $client = new Client(new Builder($trackedClient));
        $client->addCache(new ArrayAdapter());
        $client->getHttpClient()->get('/repos/owner/repo/tags');
        $this->assertSame(5000, $trackedClient->getRecentRemaining());

        $clock->sleep(31);
        $client->getHttpClient()->get('/repos/owner/repo/tags');
        $this->assertNull($trackedClient->getRecentRemaining());
        $this->assertCount(0, $responses);
    }

    private function response(int $remaining = 5000, array $headers = [], int $status = 200): Response
    {
        return new Response($status, array_replace([
            'X-RateLimit-Resource' => 'core',
            'X-RateLimit-Remaining' => (string) $remaining,
            'X-RateLimit-Reset' => (string) strtotime('2026-11-20T14:00:00Z'),
        ], $headers), '[]');
    }
}
