<?php

namespace App\Tests\Github;

use App\Github\ClientDiscovery;
use App\Github\RateLimitClient;
use App\Repository\UserRepository;
use Github\Client as GithubClient;
use Github\HttpClient\Builder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Http\Adapter\Guzzle7\Client as Guzzle7Client;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Predis\Client as RedisClient;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;

class ClientDiscoveryTest extends WebTestCase
{
    public function testUseApplicationDefaultClient(): void
    {
        $userRepository = $this->createStub(UserRepository::class);

        $responses = new MockHandler([
            // first rate_limit, it'll be ok because remaining > 50
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 200, 'remaining' => ClientDiscovery::THRESHOLD_RATE_REMAIN_APP + 1]]])),
        ]);

        $clientHandler = HandlerStack::create($responses);
        $guzzleClient = new Client([
            'handler' => $clientHandler,
        ]);

        $httpClient = new Guzzle7Client($guzzleClient);
        $httpBuilder = new Builder($httpClient);
        $githubClient = new GithubClient($httpBuilder);
        $redis = new RedisClient();

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

        $this->assertInstanceOf(GithubClient::class, $resClient);
        $this->assertSame('RateLimit ok (' . (ClientDiscovery::THRESHOLD_RATE_REMAIN_APP + 1) . ') with default application', $records[0]['message']);
    }

    public function testUseUserToken(): void
    {
        $userRepository = $this->createMock(UserRepository::class);

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
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 200, 'remaining' => ClientDiscovery::THRESHOLD_RATE_REMAIN_APP - 40]]])),
            // second rate_limit, it won't be ok because remaining < 50
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 200, 'remaining' => ClientDiscovery::THRESHOLD_RATE_REMAIN_USER - 20]]])),
            // third rate_limit, it'll' be ok because remaining > 50
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 200, 'remaining' => ClientDiscovery::THRESHOLD_RATE_REMAIN_USER + 150]]])),
        ]);

        $clientHandler = HandlerStack::create($responses);
        $guzzleClient = new Client([
            'handler' => $clientHandler,
        ]);

        $httpClient = new Guzzle7Client($guzzleClient);
        $httpBuilder = new Builder($httpClient);
        $githubClient = new GithubClient($httpBuilder);
        $redis = new RedisClient();

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

        $this->assertInstanceOf(GithubClient::class, $resClient);
        $this->assertSame('RateLimit ok (' . (ClientDiscovery::THRESHOLD_RATE_REMAIN_USER + 150) . ') with user: lion', $records[0]['message']);
    }

    public function testTokenSelectionStillUsesExplicitQuotaChecks(): void
    {
        $clock = new MockClock('2026-11-20T13:00:00Z');
        $reset = $clock->now()->getTimestamp() + 3600;
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects($this->once())->method('findAllTokens')->willReturn([
            ['username' => 'bob', 'accessToken' => 'bob-token'],
        ]);
        $responses = new MockHandler([
            new Response(200, [
                'Content-Type' => 'application/json',
                'X-RateLimit-Resource' => 'core',
                'X-RateLimit-Remaining' => '5000',
                'X-RateLimit-Reset' => (string) $reset,
            ], (string) json_encode(['resources' => ['core' => ['reset' => $reset, 'limit' => 5000, 'remaining' => 0]]])),
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['resources' => ['core' => ['reset' => $reset, 'limit' => 5000, 'remaining' => 2500]]])),
        ]);
        $trackedClient = new RateLimitClient(new Guzzle7Client(new Client(['handler' => $responses])), $clock);
        $logger = new Logger('test', [$logHandler = new TestHandler()]);
        $discovery = new ClientDiscovery($userRepository, new RedisClient(), 'client_id', 'client_secret', $logger, rateLimitClient: $trackedClient);
        $discovery->setGithubClient(new GithubClient(new Builder($trackedClient)));

        $this->assertInstanceOf(GithubClient::class, $discovery->find());
        $this->assertCount(0, $responses);
        $this->assertTrue($logHandler->hasNoticeThatContains('with user: bob'));
        $this->assertNull($trackedClient->getRecentRemaining(), 'The application quota must not survive a token change with missing headers.');
    }

    public function testNoTokenAvailable(): void
    {
        $userRepository = $this->createMock(UserRepository::class);

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
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 200, 'remaining' => ClientDiscovery::THRESHOLD_RATE_REMAIN_APP - 10]]])),
            // second rate_limit, it won't be ok because remaining < 50
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 200, 'remaining' => ClientDiscovery::THRESHOLD_RATE_REMAIN_APP - 20]]])),
        ]);

        $clientHandler = HandlerStack::create($responses);
        $guzzleClient = new Client([
            'handler' => $clientHandler,
        ]);

        $httpClient = new Guzzle7Client($guzzleClient);
        $httpBuilder = new Builder($httpClient);
        $githubClient = new GithubClient($httpBuilder);
        $redis = new RedisClient();

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

        $this->assertNull($resClient);

        $this->assertSame('No way to authenticate a client with enough rate limit remaining :(', $records[0]['message']);
    }

    public function testOneCallFail(): void
    {
        $userRepository = $this->createMock(UserRepository::class);

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
            new Response(400, ['Content-Type' => 'application/json'], (string) json_encode(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 200, 'remaining' => ClientDiscovery::THRESHOLD_RATE_REMAIN_USER + 100]]])),
            // second rate_limit, it'll be ok because remaining > 50
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 200, 'remaining' => ClientDiscovery::THRESHOLD_RATE_REMAIN_USER + 100]]])),
        ]);

        $clientHandler = HandlerStack::create($responses);
        $guzzleClient = new Client([
            'handler' => $clientHandler,
        ]);

        $httpClient = new Guzzle7Client($guzzleClient);
        $httpBuilder = new Builder($httpClient);
        $githubClient = new GithubClient($httpBuilder);
        $redis = new RedisClient();

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

        $this->assertInstanceOf(GithubClient::class, $resClient);
        $this->assertSame('RateLimit call goes bad.', $records[0]['message']);
        $this->assertSame('RateLimit ok (' . (ClientDiscovery::THRESHOLD_RATE_REMAIN_USER + 100) . ') with user: bob', $records[1]['message']);
    }

    /**
     * Using only mocks for request.
     */
    public function testFunctionnal(): void
    {
        $client = static::createClient();

        try {
            self::getContainer()->get('snc_redis.guzzle_cache')->connect();
        } catch (\Exception) {
            $this->markTestSkipped('Redis is not installed/activated');
        }

        $responses = new MockHandler([
            // first rate_limit request fail (Github booboo)
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 200, 'remaining' => ClientDiscovery::THRESHOLD_RATE_REMAIN_APP - 10]]])),
            // second rate_limit, it'll be ok because remaining > 50
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 200, 'remaining' => ClientDiscovery::THRESHOLD_RATE_REMAIN_USER + 100]]])),
        ]);

        $clientHandler = HandlerStack::create($responses);
        $guzzleClient = new Client([
            'handler' => $clientHandler,
        ]);

        $httpClient = new Guzzle7Client($guzzleClient);
        $httpBuilder = new Builder($httpClient);
        $githubClient = new GithubClient($httpBuilder);

        $disco = self::getContainer()->get(ClientDiscovery::class);
        $disco->setGithubClient($githubClient);

        $resClient = $disco->find();

        $this->assertInstanceOf(GithubClient::class, $resClient);
    }
}
