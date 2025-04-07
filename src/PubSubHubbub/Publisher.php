<?php

namespace App\PubSubHubbub;

use App\Repository\UserRepository;
use GuzzleHttp\Client;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Publish feed to pubsubhubbub.appspot.com.
 */
class Publisher
{
    /**
     * Create a new publisher.
     *
     * @param string          $hub    A hub (url) to ping
     * @param RouterInterface $router Symfony Router to generate the feed xml
     * @param Client          $client Guzzle client to send the request
     * @param string          $host   Host of the project (used to generate route from a command)
     * @param string          $scheme Scheme of the project (used to generate route from a command)
     */
    public function __construct(protected $hub, protected RouterInterface $router, protected Client $client, protected UserRepository $userRepository, $host, $scheme)
    {
        // allow generating url from command to use the correct host/scheme (instead of http://localhost)
        // @see http://symfony.com/doc/current/console/request_context.html
        $context = $this->router->getContext();
        $context->setHost($host);
        $context->setScheme($scheme);
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
        return !(204 !== $response->getStatusCode());
    }

    /**
     * Retrieve user feed urls from a list of repository ids.
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
