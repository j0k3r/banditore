<?php

namespace App\Github;

use Github\Api\RateLimit;
use Github\Client;
use Github\Exception\ApiLimitExceedException;
use Http\Client\Exception\HttpException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

trait RateLimitTrait
{
    private int $rateLimitReset = 0;

    private function deferSync(?\Throwable $previous = null): never
    {
        $reset = $previous instanceof ApiLimitExceedException ? $previous->getResetTime() : $this->rateLimitReset;

        throw new RecoverableMessageHandlingException('GitHub quota unavailable, retrying later.', previous: $previous, retryDelay: max(60, $reset - time() + 1) * 1000);
    }

    /**
     * Retrieve rate limit for the given authenticated client.
     * It's in a separate method to be able to catch error in case of glimpse on the Github side.
     *
     * @return false|int
     */
    private function getRateLimits(Client $client, LoggerInterface $logger)
    {
        $this->rateLimitReset = 0;

        try {
            /** @var RateLimit */
            $rateLimit = $client->api('rate_limit');

            $rateLimitResource = $rateLimit->getResource('core');

            if (false === $rateLimitResource) {
                throw new \Exception('Unable to retrieve "core" resource from RateLimitTrait');
            }

            $this->rateLimitReset = $rateLimitResource->getReset();

            return $rateLimitResource->getRemaining();
        } catch (HttpException $e) {
            $logger->error('RateLimit call goes bad.', ['exception' => $e]);

            return false;
        } catch (\Exception $e) {
            $logger->error('RateLimit call goes REALLY bad.', ['exception' => $e]);

            return false;
        }
    }
}
