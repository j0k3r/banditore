<?php

namespace AppBundle\PubSubHubbub;

use AppBundle\Repository\UserRepository;
use GuzzleHttp\Client;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Publish feed to pubsubhubbub.appspot.com.
 */
class Publisher
{
    protected $hub = '';
    protected $router;
    protected $client;
    protected $userRepository;

    /**
     * Create a new publisher.
     *
     * @param string         $hub            A hub (url) to ping
     * @param Router         $router         Symfony Router to generate the feed xml
     * @param Client         $client         Guzzle client to send the request
     * @param UserRepository $userRepository
     */
    public function __construct($hub, Router $router, Client $client, UserRepository $userRepository)
    {
        $this->hub = $hub;
        $this->router = $router;
        $this->client = $client;
        $this->userRepository = $userRepository;
    }

    /**
     * Ping available hub when new items are cached.
     *
     * http://nathangrigg.net/2012/09/real-time-publishing/
     *
     * @param array $repoIds Id of repo from the database
     *
     * @return bool
     */
    public function pingHub(array $repoIds)
    {
        if (empty($this->hub) || empty($repoIds)) {
            return false;
        }

        $urls = $this->retrieveFeedUrls($repoIds);

        // ping publisher
        // https://github.com/pubsubhubbub/php-publisher/blob/master/library/Publisher.php
        $params = 'hub.mode=publish';
        foreach ($urls as $url) {
            $params .= '&hub.url=' . $url;
        }

        $response = $this->client->post(
            $this->hub,
            [
                'http_errors' => false,
                'body' => $params,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'User-Agent' => 'Banditore/1.0',
                ],
            ]
        );

        // hub should response 204 if everything went fine
        return !($response->getStatusCode() !== 204);
    }

    /**
     * Retrieve user feed urls from a list of repository ids.
     *
     * @param array $repoIds
     *
     * @return array
     */
    private function retrieveFeedUrls(array $repoIds)
    {
        $users = $this->userRepository->findByRepoIds($repoIds);

        $urls = [];
        foreach ($users as $user) {
            $urls[] = $this->router->generate(
                'rss_user',
                ['uuid' => $user['uuid']],
                UrlGeneratorInterface::ABSOLUTE_URL
            );
        }

        return $urls;
    }
}
