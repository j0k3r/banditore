<?php

namespace App\Github;

use App\Cache\CustomRedisCachePool;
use App\Repository\UserRepository;
use Github\Client as GithubClient;
use Predis\Client as RedisClient;
use Psr\Log\LoggerInterface;

/**
 * This class aim to find the best authenticated method to avoid hitting the Github rate limit.
 * We first try with the default application authentication.
 * And if it fails, we'll try each user until we find one with enough rate limit.
 * In fact, the more user in database, the bigger chance to never hit the rate limit.
 */
class ClientDiscovery
{
    use RateLimitTrait;

    const THRESHOLD_RATE_REMAIN_APP = 200;
    const THRESHOLD_RATE_REMAIN_USER = 2000;

    private $userRepository;
    private $redis;
    private $clientId;
    private $clientSecret;
    private $logger;
    private $client;

    public function __construct(UserRepository $userRepository, RedisClient $redis, $clientId, $clientSecret, LoggerInterface $logger)
    {
        $this->userRepository = $userRepository;
        $this->redis = $redis;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->logger = $logger;

        $this->client = new GithubClient();
    }

    /**
     * Allow to override Github client.
     * Only used in test.
     */
    public function setGithubClient(GithubClient $client)
    {
        $this->client = $client;
    }

    /**
     * Find the best authentication to use:
     *     - check the rate limit of the application default client (which should be used in most case)
     *     - if the rate limit is too low for the application client, loop on all user to check their rate limit
     *     - if none client have enough rate limit, we'll have a problem to perform further request, stop every thing !
     *
     * @return GithubClient|null
     */
    public function find()
    {
        // attache the cache in anycase
        $this->client->addCache(
            new CustomRedisCachePool($this->redis),
            [
                // the default config include "private" to avoid caching request with this header
                // since we can use a user token, Github will return a "private" but we want to cache that request
                // it's safe because we don't require critical user value
                'respect_response_cache_directives' => ['no-cache', 'max-age', 'no-store'],
            ]
        );

        // try with the application default client
        $this->client->authenticate($this->clientId, $this->clientSecret, GithubClient::AUTH_HTTP_PASSWORD);

        $remaining = $this->getRateLimits($this->client, $this->logger);
        if ($remaining >= self::THRESHOLD_RATE_REMAIN_APP) {
            $this->logger->notice('RateLimit ok (' . $remaining . ') with default application');

            return $this->client;
        }

        // if it doesn't work, try with all user tokens
        // when at least one is ok, use it!
        $users = $this->userRepository->findAllTokens();
        foreach ($users as $user) {
            $this->client->authenticate($user['accessToken'], null, GithubClient::AUTH_HTTP_TOKEN);

            $remaining = $this->getRateLimits($this->client, $this->logger);
            if ($remaining >= self::THRESHOLD_RATE_REMAIN_USER) {
                $this->logger->notice('RateLimit ok (' . $remaining . ') with user: ' . $user['username']);

                return $this->client;
            }
        }

        $this->logger->warning('No way to authenticate a client with enough rate limit remaining :(');
    }
}
