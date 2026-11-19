<?php

namespace App\Github;

use Http\Client\Common\Plugin;
use Http\Promise\Promise;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * Retry Markdown rendering only: this POST is read-only, unlike other GitHub POSTs.
 */
final class RetryAfterPlugin implements Plugin
{
    private const MAX_RETRIES = 2;
    private const MAX_DELAY = 300;

    public function __construct(private ClockInterface $clock = new NativeClock(), private LoggerInterface $logger = new NullLogger())
    {
    }

    public function handleRequest(RequestInterface $request, callable $next, callable $first): Promise
    {
        if ('/markdown' !== $request->getUri()->getPath()) {
            return $next($request);
        }

        return $next($request)->then(function (ResponseInterface $response) use ($request, $next): ResponseInterface {
            for ($attempt = 0; $attempt < self::MAX_RETRIES; ++$attempt) {
                $delay = $this->getRetryDelay($response, $attempt);
                if (null === $delay) {
                    break;
                }

                // Leave long cooldowns to the next scheduled sync rather than retrying too early.
                if ($delay > self::MAX_DELAY) {
                    $this->logger->warning('GitHub Markdown cooldown exceeds the retry window.', ['delay' => $delay]);

                    break;
                }

                $this->logger->warning('Retrying GitHub Markdown rendering.', ['delay' => $delay, 'attempt' => $attempt + 1]);
                $this->clock->sleep($delay);

                $request->getBody()->rewind();
                /** @var ResponseInterface $response */
                $response = $next($request)->wait();
            }

            return $response;
        });
    }

    private function getRetryDelay(ResponseInterface $response, int $attempt): ?int
    {
        $status = $response->getStatusCode();
        if (403 !== $status && 429 !== $status) {
            return null;
        }

        if (403 === $status && !$response->hasHeader('Retry-After') && '0' !== $response->getHeaderLine('X-RateLimit-Remaining')) {
            $body = (string) $response->getBody();
            if (!str_contains($body, 'secondary rate limit') && !str_contains($body, 'abuse detection mechanism')) {
                return null;
            }
        }

        $retryAfter = $response->getHeaderLine('Retry-After');
        if (ctype_digit($retryAfter)) {
            return (int) $retryAfter;
        }

        $now = $this->clock->now()->getTimestamp();
        $retryAt = '' !== $retryAfter ? strtotime($retryAfter) : false;
        if (false !== $retryAt) {
            return max(0, $retryAt - $now);
        }

        $reset = $response->getHeaderLine('X-RateLimit-Reset');
        if ('0' === $response->getHeaderLine('X-RateLimit-Remaining') && ctype_digit($reset)) {
            return max(0, (int) $reset - $now);
        }

        return 60 * (2 ** $attempt);
    }
}
