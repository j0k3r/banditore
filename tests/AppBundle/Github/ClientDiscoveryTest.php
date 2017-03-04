<?php

namespace Tests\AppBundle\Github;

use AppBundle\Github\ClientDiscovery;
use Github\Client as GithubClient;
use Github\HttpClient\Builder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Http\Adapter\Guzzle6\Client as Guzzle6Client;
use M6Web\Component\RedisMock\RedisMockFactory;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ClientDiscoveryTest extends WebTestCase
{
    public function testUseApplicationDefaultClient()
    {
        $userRepository = $this->getMockBuilder('AppBundle\Repository\UserRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $responses = new MockHandler([
            // first rate_limit, it'll be ok because remaining > 50
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => ClientDiscovery::THRESHOLD_BAD_AUTH + 1]]])),
        ]);

        $clientHandler = HandlerStack::create($responses);
        $guzzleClient = new Client([
            'handler' => $clientHandler,
        ]);

        $httpClient = new Guzzle6Client($guzzleClient);
        $httpBuilder = new Builder($httpClient);
        $githubClient = new GithubClient($httpBuilder);

        $redis = (new RedisMockFactory())->getAdapter('Predis\Client', true);

        $logger = new Logger('foo');
        $logHandler = new TestHandler();
        $logger->pushHandler($logHandler);

        $disco = new ClientDiscovery(
            $userRepository,
            $redis,
            'client_id',
            'client_secret',
            $logger
        );
        $disco->setGithubClient($githubClient);

        $resClient = $disco->find();

        $records = $logHandler->getRecords();

        $this->assertInstanceOf('Github\Client', $resClient);
        $this->assertSame('RateLimit ok with default application', $records[0]['message']);
    }

    public function testUseUserToken()
    {
        $userRepository = $this->getMockBuilder('AppBundle\Repository\UserRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $userRepository->expects($this->once())
            ->method('findAllTokens')
            ->willReturn([
                [
                    'id' => '123',
                    'username' => 'bob',
                    'accessToken' => '123123',
                ],
                [
                    'id' => '456',
                    'username' => 'lion',
                    'accessToken' => '456456',
                ],
            ]);

        $responses = new MockHandler([
            // first rate_limit, it won't be ok because remaining < 50
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => ClientDiscovery::THRESHOLD_BAD_AUTH - 40]]])),
            // second rate_limit, it won't be ok because remaining < 50
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => ClientDiscovery::THRESHOLD_BAD_AUTH - 20]]])),
            // third rate_limit, it'll' be ok because remaining > 50
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => ClientDiscovery::THRESHOLD_BAD_AUTH + 150]]])),
        ]);

        $clientHandler = HandlerStack::create($responses);
        $guzzleClient = new Client([
            'handler' => $clientHandler,
        ]);

        $httpClient = new Guzzle6Client($guzzleClient);
        $httpBuilder = new Builder($httpClient);
        $githubClient = new GithubClient($httpBuilder);

        $redis = (new RedisMockFactory())->getAdapter('Predis\Client', true);

        $logger = new Logger('foo');
        $logHandler = new TestHandler();
        $logger->pushHandler($logHandler);

        $disco = new ClientDiscovery(
            $userRepository,
            $redis,
            'client_id',
            'client_secret',
            $logger
        );
        $disco->setGithubClient($githubClient);

        $resClient = $disco->find();

        $records = $logHandler->getRecords();

        $this->assertInstanceOf('Github\Client', $resClient);
        $this->assertSame('RateLimit ok with user: lion', $records[0]['message']);
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage No way to authenticate a client with enough rate limit remaining :(
     */
    public function testNoTokenAvailable()
    {
        $userRepository = $this->getMockBuilder('AppBundle\Repository\UserRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $userRepository->expects($this->once())
            ->method('findAllTokens')
            ->willReturn([
                [
                    'id' => '123',
                    'username' => 'bob',
                    'accessToken' => '123123',
                ],
            ]);

        $responses = new MockHandler([
            // first rate_limit, it won't be ok because remaining < 50
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => ClientDiscovery::THRESHOLD_BAD_AUTH - 10]]])),
            // second rate_limit, it won't be ok because remaining < 50
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => ClientDiscovery::THRESHOLD_BAD_AUTH - 20]]])),
        ]);

        $clientHandler = HandlerStack::create($responses);
        $guzzleClient = new Client([
            'handler' => $clientHandler,
        ]);

        $httpClient = new Guzzle6Client($guzzleClient);
        $httpBuilder = new Builder($httpClient);
        $githubClient = new GithubClient($httpBuilder);

        $redis = (new RedisMockFactory())->getAdapter('Predis\Client', true);

        $disco = new ClientDiscovery(
            $userRepository,
            $redis,
            'client_id',
            'client_secret',
            new NullLogger()
        );
        $disco->setGithubClient($githubClient);

        $resClient = $disco->find();

        $this->assertInstanceOf('Github\Client', $resClient);
    }

    public function testOneCallFail()
    {
        $userRepository = $this->getMockBuilder('AppBundle\Repository\UserRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $userRepository->expects($this->once())
            ->method('findAllTokens')
            ->willReturn([
                [
                    'id' => '123',
                    'username' => 'bob',
                    'accessToken' => '123123',
                ],
            ]);

        $responses = new MockHandler([
            // first rate_limit request fail (Github booboo)
            new Response(400, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => ClientDiscovery::THRESHOLD_BAD_AUTH + 100]]])),
            // second rate_limit, it'll be ok because remaining > 50
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => ClientDiscovery::THRESHOLD_BAD_AUTH + 100]]])),
        ]);

        $clientHandler = HandlerStack::create($responses);
        $guzzleClient = new Client([
            'handler' => $clientHandler,
        ]);

        $httpClient = new Guzzle6Client($guzzleClient);
        $httpBuilder = new Builder($httpClient);
        $githubClient = new GithubClient($httpBuilder);

        $redis = (new RedisMockFactory())->getAdapter('Predis\Client', true);

        $logger = new Logger('foo');
        $logHandler = new TestHandler();
        $logger->pushHandler($logHandler);

        $disco = new ClientDiscovery(
            $userRepository,
            $redis,
            'client_id',
            'client_secret',
            $logger
        );
        $disco->setGithubClient($githubClient);

        $resClient = $disco->find();

        $records = $logHandler->getRecords();

        $this->assertInstanceOf('Github\Client', $resClient);
        $this->assertSame('RateLimit call goes bad.', $records[0]['message']);
        $this->assertSame('RateLimit ok with user: bob', $records[1]['message']);
    }

    /**
     * Using only mocks for request.
     */
    public function testFunctionnal()
    {
        $client = static::createClient();
        $container = $client->getContainer();

        $responses = new MockHandler([
            // first rate_limit request fail (Github booboo)
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => ClientDiscovery::THRESHOLD_BAD_AUTH - 10]]])),
            // second rate_limit, it'll be ok because remaining > 50
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => ClientDiscovery::THRESHOLD_BAD_AUTH + 100]]])),
        ]);

        $clientHandler = HandlerStack::create($responses);
        $guzzleClient = new Client([
            'handler' => $clientHandler,
        ]);

        $httpClient = new Guzzle6Client($guzzleClient);
        $httpBuilder = new Builder($httpClient);
        $githubClient = new GithubClient($httpBuilder);

        $disco = $container->get('banditore.github.client_discovery');
        $disco->setGithubClient($githubClient);

        $resClient = $disco->find();

        $this->assertInstanceOf('Github\Client', $resClient);
    }
}
