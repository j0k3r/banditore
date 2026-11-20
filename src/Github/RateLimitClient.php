<?php

namespace App\Github;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * Read network headers before the HTTP cache replaces 304 responses with cached ones.
 */
final class RateLimitClient implements ClientInterface
{
    // shortcut: snapshots can lag other workers by 30s; use shared limiting if quota contention becomes significant.
    private const MAX_AGE = 30;

    private ?int $remaining = null;
    private int $expiresAt = 0;

    public function __construct(private ClientInterface $client, private ClockInterface $clock = new NativeClock())
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->clear();
        $startedAt = $this->clock->now()->getTimestamp();
        $response = $this->client->sendRequest($request);

        $remaining = filter_var($response->getHeaderLine('X-RateLimit-Remaining'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $reset = filter_var($response->getHeaderLine('X-RateLimit-Reset'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ('core' === $response->getHeaderLine('X-RateLimit-Resource') && false !== $remaining && false !== $reset) {
            $this->remaining = $remaining;
            $this->expiresAt = min($reset, $startedAt + self::MAX_AGE);
        }

        return $response;
    }

    /**
     * Return null when an explicit quota check is required.
     */
    public function getRecentRemaining(): ?int
    {
        if ($this->clock->now()->getTimestamp() >= $this->expiresAt) {
            return null;
        }

        // Other workers share this quota: refresh it explicitly near exhaustion.
        if (null !== $this->remaining && 0 !== $this->remaining && $this->remaining < ClientDiscovery::THRESHOLD_RATE_REMAIN_APP) {
            return null;
        }

        return $this->remaining;
    }

    public function clear(): void
    {
        $this->remaining = null;
        $this->expiresAt = 0;
    }
}
