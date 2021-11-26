<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Github\Client as GithubClient;
use Github\HttpClient\Builder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Http\Adapter\Guzzle6\Client as Guzzle6Client;
use KnpU\OAuth2ClientBundle\Client\OAuth2Client;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class GithubAuthenticatorTest extends WebTestCase
{
    public function testCallbackWithExistingUser(): void
    {
        $client = static::createClient();

        $responses = new MockHandler([
            // /login/oauth/access_token (to retrieve the access_token from `authenticate()`)
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'access_token' => 'blablabla',
            ])),
            // /api/v3/user (to retrieve user information from Github)
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
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

        self::getContainer()->set('banditore.client.github.application', $githubClient);
        self::getContainer()->get('oauth2.registry')->getClient('github')->getOAuth2Provider()->setHttpClient($guzzleClient);

        self::getContainer()->get('session')->set(OAuth2Client::OAUTH2_SESSION_STATE_KEY, 'MyAwesomeState');

        // before login
        /** @var User */
        $user = self::getContainer()->get(UserRepository::class)->find(123);
        $this->assertSame('1234567890', $user->getAccessToken());
        $this->assertSame('http://0.0.0.0/avatar.jpg', $user->getAvatar());

        $client->request('GET', '/callback?state=MyAwesomeState&code=MyAwesomeCode');

        // after login
        /** @var User */
        $user = self::getContainer()->get(UserRepository::class)->find(123);
        $this->assertSame('blablabla', $user->getAccessToken());
        $this->assertSame('http://avat.ar/my.png', $user->getAvatar());

        $this->assertSame(302, $client->getResponse()->getStatusCode());
        /** @var \Symfony\Component\HttpFoundation\RedirectResponse */
        $response = $client->getResponse();
        $this->assertSame('/dashboard', $response->getTargetUrl());

        $message = self::getContainer()->get('session')->getFlashBag()->get('info');
        $this->assertSame('Successfully logged in!', $message[0]);

        $transport = self::getContainer()->get('messenger.transport.sync_starred_repos');
        $this->assertCount(1, $transport->get());

        $messages = (array) $transport->get();
        /** @var \App\Message\StarredReposSync */
        $message = $messages[0]->getMessage();
        $this->assertSame(123, $message->getUserId());
    }

    public function testCallbackWithNewUser(): void
    {
        $client = static::createClient();

        $responses = new MockHandler([
            // /login/oauth/access_token (to retrieve the access_token from `authenticate()`)
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'access_token' => 'superboum',
            ])),
            // /api/v3/user (to retrieve user information from Github)
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
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

        self::getContainer()->set('banditore.client.github.application', $githubClient);
        self::getContainer()->get('oauth2.registry')->getClient('github')->getOAuth2Provider()->setHttpClient($guzzleClient);

        self::getContainer()->get('session')->set(OAuth2Client::OAUTH2_SESSION_STATE_KEY, 'MyAwesomeState');

        // before login
        $user = self::getContainer()->get(UserRepository::class)->find(456);
        $this->assertNull($user, 'User 456 does not YET exist');

        $client->request('GET', '/callback?state=MyAwesomeState&code=MyAwesomeCode');

        // after login
        /** @var User */
        $user = self::getContainer()->get(UserRepository::class)->find(456);
        $this->assertSame('superboum', $user->getAccessToken());
        $this->assertSame('http://avat.ar/down.png', $user->getAvatar());
        $this->assertSame('getdown', $user->getUsername());
        $this->assertSame('Any', $user->getName());

        $this->assertSame(302, $client->getResponse()->getStatusCode());
        /** @var \Symfony\Component\HttpFoundation\RedirectResponse */
        $response = $client->getResponse();
        $this->assertSame('/dashboard', $response->getTargetUrl());

        $message = self::getContainer()->get('session')->getFlashBag()->get('info');
        $this->assertSame('Successfully logged in. Your starred repos will soon be synced!', $message[0]);

        $transport = self::getContainer()->get('messenger.transport.sync_starred_repos');
        $this->assertCount(1, $transport->get());

        $messages = (array) $transport->get();
        /** @var \App\Message\StarredReposSync */
        $message = $messages[0]->getMessage();
        $this->assertSame(456, $message->getUserId());
    }
}
