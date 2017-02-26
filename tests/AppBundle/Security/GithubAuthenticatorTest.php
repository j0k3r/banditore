<?php

namespace Tests\AppBundle\Security;

use AppBundle\Entity\User;
use Github\Client as GithubClient;
use Github\HttpClient\Builder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Http\Adapter\Guzzle6\Client as Guzzle6Client;
use KnpU\OAuth2ClientBundle\Client\OAuth2Client;
use Swarrot\Broker\Message;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class GithubAuthenticatorTest extends WebTestCase
{
    public function testCallbackWithExistingUser()
    {
        $client = static::createClient();
        $container = $client->getContainer();

        $responses = new MockHandler([
            // /login/oauth/access_token (to retrieve the access_token from `getCredentials()`)
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => 'blablabla',
            ])),
            // /api/v3/user (to retrieve user information from Github)
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 123,
                'email' => 'toto@test.io',
                'name' => 'Bob',
                'login' => 'admin',
                'avatar_url' => 'http://avat.ar/my.png',
            ])),
        ]);

        $clientHandler = HandlerStack::create($responses);
        $guzzleClient = new Client([
            'handler' => $clientHandler,
        ]);

        $httpClient = new Guzzle6Client($guzzleClient);
        $httpBuilder = new Builder($httpClient);
        $githubClient = new GithubClient($httpBuilder);

        $publisher = $this->getMockBuilder('Swarrot\SwarrotBundle\Broker\Publisher')
            ->disableOriginalConstructor()
            ->getMock();
        $publisher->expects($this->once())
            ->method('publish')
            ->with('banditore.sync_user_repo.publisher', new Message(json_encode(['user_id' => 123])));

        $container->set('swarrot.publisher', $publisher);
        $container->set('banditore.client.github.application', $githubClient);
        $container->get('oauth2.registry')->getClient('github')->getOAuth2Provider()->setHttpClient($guzzleClient);

        $container->get('session')->set(OAuth2Client::OAUTH2_SESSION_STATE_KEY, 'MyAwesomeState');

        // before login
        $user = $container->get('banditore.repository.user')->find(123);
        $this->assertSame('1234567890', $user->getAccessToken());
        $this->assertSame('http://0.0.0.0/avatar.jpg', $user->getAvatar());

        $client->request('GET', '/callback?state=MyAwesomeState&code=MyAwesomeCode');

        // after login
        $user = $container->get('banditore.repository.user')->find(123);
        $this->assertSame('blablabla', $user->getAccessToken());
        $this->assertSame('http://avat.ar/my.png', $user->getAvatar());

        $this->assertSame(302, $client->getResponse()->getStatusCode());
        $this->assertSame('/dashboard?sync=1', $client->getResponse()->getTargetUrl());
    }

    public function testCallbackWithNewUser()
    {
        $client = static::createClient();
        $container = $client->getContainer();

        $responses = new MockHandler([
            // /login/oauth/access_token (to retrieve the access_token from `getCredentials()`)
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => 'superboum',
            ])),
            // /api/v3/user (to retrieve user information from Github)
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 456,
                'email' => 'down@g.et',
                'name' => 'Any',
                'login' => 'getdown',
                'avatar_url' => 'http://avat.ar/down.png',
            ])),
        ]);

        $clientHandler = HandlerStack::create($responses);
        $guzzleClient = new Client([
            'handler' => $clientHandler,
        ]);

        $httpClient = new Guzzle6Client($guzzleClient);
        $httpBuilder = new Builder($httpClient);
        $githubClient = new GithubClient($httpBuilder);

        $publisher = $this->getMockBuilder('Swarrot\SwarrotBundle\Broker\Publisher')
            ->disableOriginalConstructor()
            ->getMock();
        $publisher->expects($this->once())
            ->method('publish')
            ->with('banditore.sync_user_repo.publisher', new Message(json_encode(['user_id' => 456])));

        $container->set('swarrot.publisher', $publisher);
        $container->set('banditore.client.github.application', $githubClient);
        $container->get('oauth2.registry')->getClient('github')->getOAuth2Provider()->setHttpClient($guzzleClient);

        $container->get('session')->set(OAuth2Client::OAUTH2_SESSION_STATE_KEY, 'MyAwesomeState');

        // before login
        $user = $container->get('banditore.repository.user')->find(456);
        $this->assertNull($user, 'User 456 does not YET exist');

        $client->request('GET', '/callback?state=MyAwesomeState&code=MyAwesomeCode');

        // after login
        $user = $container->get('banditore.repository.user')->find(456);
        $this->assertSame('superboum', $user->getAccessToken());
        $this->assertSame('http://avat.ar/down.png', $user->getAvatar());
        $this->assertSame('getdown', $user->getUsername());
        $this->assertSame('Any', $user->getName());

        $this->assertSame(302, $client->getResponse()->getStatusCode());
        $this->assertSame('/dashboard?sync=1', $client->getResponse()->getTargetUrl());
    }
}
