<?php

namespace App\Github;

use Github\Client;
use Http\Client\Exception\HttpException;
use Psr\Log\LoggerInterface;

trait RateLimitTrait
{
    /**
     * Retrieve rate limit for the given authenticated client.
     * It's in a separate method to be able to catch error in case of glimpse on the Github side.
     *
     * @return false|int
     */
    private function getRateLimits(Client $client, LoggerInterface $logger)
    {
        try {
            /** @var \Github\Api\RateLimit */
            $rateLimit = $client->api('rate_limit');

            return $rateLimit->getResource('core')->getRemaining();
        } catch (HttpException $e) {
            $logger->error('RateLimit call goes bad.', ['exception' => $e]);

            return false;
        } catch (\Exception $e) {
            $logger->error('RateLimit call goes REALLY bad.', ['exception' => $e]);

            return false;
        }
    }
}
