<?php

namespace AppBundle\Github;

use AppBundle\Repository\UserRepository;
use Github\Client;

/**
 * Asbtract repository to define frequently used queries for Github.
 */
abstract class Repository
{
    const NB_VERSIONS = 10;
    protected $client;
    protected $userRepository;

    public function __construct(Client $client, UserRepository $userRepository)
    {
        $this->client = $client;
        $this->userRepository = $userRepository;
    }

    /**
     * Authenticate the user in the Github API.
     *
     * @param string $username Github username
     *
     * @return Repository
     */
    public function authenticateUser($username)
    {
        $user = $this->userRepository->findOneBy(['username' => $username]);

        if (null === $user) {
            throw new \InvalidArgumentException('Can not find a user with username: "' . $username . '"');
        }

        $this->client->authenticate(
            $user->getAccessToken(),
            null,
            Client::AUTH_HTTP_TOKEN
        );

        return $this;
    }

    /**
     * Return the Github client.
     * If we want to make extra call using the same authenticated user.
     * Like for the rate limit.
     *
     * @return array
     */
    public function getRateLimits()
    {
        return $this->client->api('rate_limit')->getRateLimits();
    }

    public function markdown($content, $repo)
    {
        return $this->client->api('markdown')->render($content, 'gfm', $repo);
    }

    /**
     * Retrieve all starred repos for a given user.
     * It'll retrieve them 100 per 100 until reached the total amount.
     *
     * @param string $username Github username from which we want to retrieve starred repo
     *
     * @return array
     */
    abstract public function retrieveStarredRepos($username);

    /**
     * Retrieve all tags & releases and merge them together to got only "versions".
     *
     * @param string $repoFullName Github repo full name, like "owner/repoName"
     *
     * @return array
     */
    abstract public function retrieveTagsAndReleases($repoFullName);
}
