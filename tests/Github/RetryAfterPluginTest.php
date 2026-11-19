<?php

namespace App\Tests\Github;

use App\Github\RetryAfterPlugin;
use Github\Api\Markdown;
use Github\Client;
use Github\Exception\ErrorException;
use Github\HttpClient\Builder;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Http\Adapter\Guzzle7\Client as Guzzle7Client;
use Http\Promise\FulfilledPromise;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Clock\MockClock;

class RetryAfterPluginTest extends TestCase
{
    #[DataProvider('retryResponses')]
    public function testRetryDelay(Response $response, int $seconds): void
    {
        $clock = new MockClock('2026-11-19T12:00:00Z');
        $start = $clock->now()->getTimestamp();
        $plugin = new RetryAfterPlugin($clock);
        $request = new Request('POST', 'https://api.github.com/markdown', [], 'markdown');
        $calls = 0;
        $next = function (RequestInterface $request) use (&$calls, $response): FulfilledPromise {
            $this->assertSame('markdown', $request->getBody()->getContents());

            return new FulfilledPromise(0 === $calls++ ? $response : new Response(200, [], '<p>markdown</p>'));
        };

        $result = $plugin->handleRequest($request, $next, $next)->wait();

        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame(2, $calls);
        $this->assertSame($start + $seconds, $clock->now()->getTimestamp());
    }

    public static function retryResponses(): iterable
    {
        yield 'secondary limit' => [new Response(403, ['Retry-After' => '60']), 60];
        yield 'zero delay' => [new Response(429, ['Retry-After' => '0']), 0];
        yield 'HTTP date' => [new Response(429, ['Retry-After' => 'Thu, 19 Nov 2026 12:00:30 GMT']), 30];
        yield 'primary limit reset' => [new Response(403, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) strtotime('2026-11-19T12:00:45Z')]), 45];
        yield 'retry-after takes precedence' => [new Response(429, ['Retry-After' => '30', 'X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) strtotime('2026-11-19T13:00:00Z')]), 30];
        yield 'missing header' => [new Response(429), 60];
        yield 'secondary limit without header' => [new Response(403, [], '{"message":"You have exceeded a secondary rate limit."}'), 60];
        yield 'legacy abuse limit' => [new Response(403, [], '{"message":"You have triggered an abuse detection mechanism."}'), 60];
        yield 'invalid header' => [new Response(429, ['Retry-After' => 'invalid']), 60];
    }

    public function testRetriesAreBoundedWithExponentialBackoff(): void
    {
        $clock = new MockClock('2026-11-19T12:00:00Z');
        $plugin = new RetryAfterPlugin($clock);
        $request = new Request('POST', 'https://api.github.com/markdown');
        $calls = 0;
        $next = static function () use (&$calls): FulfilledPromise {
            ++$calls;

            return new FulfilledPromise(new Response(429));
        };

        $result = $plugin->handleRequest($request, $next, $next)->wait();

        $this->assertSame(429, $result->getStatusCode());
        $this->assertSame(3, $calls);
        $this->assertSame('12:03:00', $clock->now()->format('H:i:s'));
    }

    #[DataProvider('nonRetryResponses')]
    public function testDoesNotRetryOtherErrorsOrLongCooldowns(Response $response): void
    {
        $clock = new MockClock('2026-11-19T12:00:00Z');
        $plugin = new RetryAfterPlugin($clock);
        $request = new Request('POST', 'https://api.github.com/markdown');
        $calls = 0;
        $next = static function () use (&$calls, $response): FulfilledPromise {
            ++$calls;

            return new FulfilledPromise($response);
        };

        $this->assertSame($response, $plugin->handleRequest($request, $next, $next)->wait());
        $this->assertSame(1, $calls);
        $this->assertSame('12:00:00', $clock->now()->format('H:i:s'));
    }

    public static function nonRetryResponses(): iterable
    {
        yield 'success' => [new Response(200)];
        yield 'bad request' => [new Response(400)];
        yield 'unauthorized' => [new Response(401)];
        yield 'forbidden without rate limit' => [new Response(403)];
        yield 'server error' => [new Response(500)];
        yield 'long retry-after' => [new Response(429, ['Retry-After' => '301'])];
        yield 'long primary reset' => [new Response(403, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) strtotime('2026-11-19T13:00:00Z')])];
    }

    public function testDoesNotRetryOtherEndpoints(): void
    {
        $plugin = new RetryAfterPlugin(new MockClock());
        $request = new Request('POST', 'https://api.github.com/repos/owner/repo/releases');
        $calls = 0;
        $next = static function () use (&$calls): FulfilledPromise {
            ++$calls;

            return new FulfilledPromise(new Response(429, ['Retry-After' => '0']));
        };

        $this->assertSame(429, $plugin->handleRequest($request, $next, $next)->wait()->getStatusCode());
        $this->assertSame(1, $calls);
    }

    public function testRetryHappensBeforeGithubThrowsAnException(): void
    {
        $responses = new MockHandler([
            new Response(403, ['Retry-After' => '0'], '{"message":"You have exceeded a secondary rate limit."}'),
            new Response(200, ['Content-Type' => 'text/html'], '<p>markdown</p>'),
            new Response(400, ['Content-Type' => 'application/json'], '{"message":"Bad request"}'),
        ]);
        $builder = new Builder(new Guzzle7Client(new \GuzzleHttp\Client(['handler' => $responses])));
        $client = new Client($builder);
        $builder->addPlugin(new RetryAfterPlugin(new MockClock()));

        $markdown = new Markdown($client);
        $this->assertSame('<p>markdown</p>', $markdown->render('markdown'));

        $this->expectException(ErrorException::class);
        $markdown->render('bad markdown');
    }
}
