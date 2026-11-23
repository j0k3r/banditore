<?php

namespace App\Tests\MessageHandler;

use App\Entity\Repo;
use App\Entity\Star;
use App\Entity\User;
use App\Github\RateLimitClient;
use App\Message\StarredReposSync;
use App\MessageHandler\StarredReposSyncHandler;
use App\Repository\RepoRepository;
use App\Repository\StarRepository;
use App\Repository\UserRepository;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\ORM\EntityManager;
use Github\Client as GithubClient;
use Github\HttpClient\Builder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Http\Adapter\Guzzle7\Client as Guzzle7Client;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

class StarredReposSyncHandlerTest extends WebTestCase
{
    public function testProcessNoUser(): void
    {
        $doctrine = $this->createStub(Registry::class);

        $userRepository = $this->createMock(UserRepository::class);

        $userRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->willReturn(null);

        $starRepository = $this->createStub(StarRepository::class);

        $repoRepository = $this->createStub(RepoRepository::class);

        $githubClient = $this->createMock(GithubClient::class);

        $githubClient->expects($this->never())
            ->method('authenticate');

        $redisClient = $this->createMock(\Predis\Client::class);

        // will use `setex` & `del` but will be called dynamically by `_call`
        $redisClient->expects($this->never())
            ->method('__call');

        $handler = new StarredReposSyncHandler(
            $doctrine,
            $userRepository,
            $starRepository,
            $repoRepository,
            $githubClient,
            new NullLogger(),
            $redisClient,
            true,
            new Logger('metrics', [$metricsLogHandler = new TestHandler()])
        );

        $this->assertFalse($handler->__invoke(new StarredReposSync(123)));
        $metrics = $this->assertMetrics($metricsLogHandler, 'user_not_found');
        $this->assertNull($metrics['user']);
        $this->assertSame(0, $metrics['page_requests']);
    }

    public static function metricsModes(): iterable
    {
        yield 'metrics disabled' => [false];
        yield 'metrics enabled' => [true];
    }

    #[DataProvider('metricsModes')]
    public function testProcessSuccessfulMessage(bool $collectMetrics): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->expects($this->once())
            ->method('isOpen')
            ->willReturn(true);

        $doctrine = $this->createMock(Registry::class);
        $doctrine->expects($this->once())
            ->method('getManager')
            ->willReturn($em);

        $user = new User();
        $user->setId(123);
        $user->setUsername('bob');
        $user->setName('Bobby');

        $userRepository = $this->createMock(UserRepository::class);

        $userRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->willReturn($user);

        $starRepository = $this->createMock(StarRepository::class);

        $starRepository->expects($this->exactly(2))
            ->method('findAllByUser')
            ->with(123)
            ->willReturn([666, 777]);

        $starRepository->expects($this->once())
            ->method('removeFromUser')
            ->with([1 => 777], 123);

        $repo = new Repo();
        $repo->setId(666);
        $repo->setFullName('j0k3r/banditore');
        $repo->setUpdatedAt(new \DateTime()->setTimestamp(time() - 3600 * 72));

        $repoRepository = $this->createMock(RepoRepository::class);

        $repoRepository->expects($this->once())
            ->method('find')
            ->with(666)
            ->willReturn($repo);

        $responses = new MockHandler([
            // /rate_limit
            $this->getOKResponse(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 200, 'remaining' => 10]]]),
            // first /user/starred
            $this->getOKResponse([[
                'description' => 'banditore',
                'homepage' => 'http://banditore.io',
                'language' => 'PHP',
                'name' => 'banditore',
                'full_name' => 'j0k3r/banditore',
                'id' => 666,
                'owner' => [
                    'avatar_url' => 'http://avatar.api/banditore.jpg',
                ],
            ]]),
        ]);

        $githubClient = $this->getMockClient($responses);

        $logger = new Logger('foo');
        $logHandler = new TestHandler();
        $logger->pushHandler($logHandler);

        $redisClient = $this->createMock(\Predis\Client::class);

        // will use `setex` & `del` but will be called dynamically by `_call`
        $redisClient->expects($this->exactly(2))
            ->method('__call');

        $handler = new StarredReposSyncHandler(
            $doctrine,
            $userRepository,
            $starRepository,
            $repoRepository,
            $githubClient,
            $logger,
            $redisClient,
            $collectMetrics,
            new Logger('metrics', [$metricsLogHandler = new TestHandler()])
        );

        $this->assertTrue($handler->__invoke(new StarredReposSync(123)));
        $this->assertCount(0, $responses);
        $this->assertCount($collectMetrics ? 1 : 0, $metricsLogHandler->getRecords());
        if ($collectMetrics) {
            $metrics = $this->assertMetrics($metricsLogHandler, 'completed');
            $this->assertSame('bob', $metrics['user']);
            $this->assertSame(1, $metrics['page_requests']);
            $this->assertSame(1, $metrics['stars_seen']);
            $this->assertSame(2, $metrics['existing_stars']);
            $this->assertSame(1, $metrics['repo_lookups']);
            $this->assertSame(1, $metrics['rate_limit_checks']);
            $this->assertSame(1, $metrics['flushes']);
            $this->assertSame(0, $metrics['added_stars']);
            $this->assertSame(1, $metrics['removed_stars']);
        }

        $records = $logHandler->getRecords();

        $this->assertSame('Consume banditore.sync_starred_repos message', $records[0]['message']);
        $this->assertSame('[10] Check <info>bob</info> … ', $records[1]['message']);
        $this->assertSame('    sync 1 starred repos', $records[2]['message']);
        $this->assertSame('Removed stars: 1', $records[3]['message']);
        $this->assertSame('Synced repos: 1', $records[4]['message']);
    }

    public static function unavailableUsers(): iterable
    {
        yield 'both endpoints return 404' => [404, 404, true];
        yield 'ID lookup fails with 500' => [404, 500, false];
        yield 'ID lookup fails with 403' => [404, 403, false];
        yield 'ID lookup is rate limited' => [404, 429, false];
        yield 'starred endpoint fails with 500' => [500, null, false];
        yield 'starred endpoint fails with 403' => [403, null, false];
        yield 'starred endpoint is rate limited' => [429, null, false];
    }

    #[DataProvider('unavailableUsers')]
    public function testUserRemovedFromGitHub(int $starredStatus, ?int $lookupStatus, bool $removed): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->expects($this->once())
            ->method('isOpen')
            ->willReturn(true);

        $doctrine = $this->createMock(Registry::class);
        $doctrine->expects($this->once())
            ->method('getManager')
            ->willReturn($em);

        $user = new User();
        $user->setId(123);
        $user->setUsername('bob');
        $user->setName('Bobby');

        $userRepository = $this->createMock(UserRepository::class);

        $userRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->willReturn($user);

        $starRepository = $this->createMock(StarRepository::class);

        $starRepository->expects($this->never())
            ->method('findAllByUser');

        $repoRepository = $this->createMock(RepoRepository::class);

        $repoRepository->expects($this->never())
            ->method('find');

        $responses = new MockHandler([
            // /rate_limit
            $this->getOKResponse(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 200, 'remaining' => 10]]]),
            // first /users/bob/starred
            new Response($starredStatus, ['Content-Type' => 'application/json']),
            ...(null === $lookupStatus ? [] : [new Response($lookupStatus, ['Content-Type' => 'application/json'])]),
        ]);

        $githubClient = $this->getMockClient($responses);

        $logger = new Logger('foo');
        $logHandler = new TestHandler();
        $logger->pushHandler($logHandler);

        $redisClient = $this->createMock(\Predis\Client::class);

        // will use `setex` & `del` but will be called dynamically by `_call`
        $redisClient->expects($this->exactly(2))
            ->method('__call');

        $handler = new StarredReposSyncHandler(
            $doctrine,
            $userRepository,
            $starRepository,
            $repoRepository,
            $githubClient,
            $logger,
            $redisClient,
            true,
            new Logger('metrics', [$metricsLogHandler = new TestHandler()])
        );

        $em->expects($removed ? $this->once() : $this->never())->method('persist')->with($user);
        $em->expects($removed ? $this->once() : $this->never())->method('flush');
        $this->assertTrue($handler->__invoke(new StarredReposSync(123)));
        $this->assertCount(0, $responses);
        $metrics = $this->assertMetrics($metricsLogHandler, 'fetch_failed');
        $this->assertSame(1, $metrics['page_requests']);
        $this->assertSame(null === $lookupStatus ? 0 : 1, $metrics['user_requests']);
        $this->assertSame(0, $metrics['stars_seen']);
        $this->assertSame($removed ? 1 : 0, $metrics['flushes']);
        $this->assertSame(0, $metrics['removed_stars']);

        $records = $logHandler->getRecords();

        $this->assertSame('Consume banditore.sync_starred_repos message', $records[0]['message']);
        $this->assertSame('[10] Check <info>bob</info> … ', $records[1]['message']);
        $this->assertStringContainsString('(starred) <error>', $records[2]['message']);

        $this->assertSame($removed, null !== $user->getRemovedAt());
    }

    public function testProcessUnexpectedError(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageIsOrContains('booboo');

        $em = $this->createMock(EntityManager::class);
        $em->expects($this->once())
            ->method('isOpen')
            ->willReturn(true);

        $doctrine = $this->createMock(Registry::class);
        $doctrine->expects($this->once())
            ->method('getManager')
            ->willReturn($em);

        $user = new User();
        $user->setId(123);
        $user->setUsername('bob');
        $user->setName('Bobby');

        $userRepository = $this->createMock(UserRepository::class);

        $userRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->willReturn($user);

        $starRepository = $this->createMock(StarRepository::class);

        $starRepository->expects($this->once())
            ->method('findAllByUser')
            ->with(123)
            ->willReturn([666]);

        $repoRepository = $this->createMock(RepoRepository::class);

        $repoRepository->expects($this->once())
            ->method('find')
            ->with(666)
            ->will($this->throwException(new \Exception('booboo')));

        $responses = new MockHandler([
            // /rate_limit
            $this->getOKResponse(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 200, 'remaining' => 10]]]),
            // first /user/starred
            $this->getOKResponse([[
                'description' => 'banditore',
                'homepage' => 'http://banditore.io',
                'language' => 'PHP',
                'name' => 'banditore',
                'full_name' => 'j0k3r/banditore',
                'id' => 666,
                'owner' => [
                    'avatar_url' => 'http://avatar.api/banditore.jpg',
                ],
            ]]),
        ]);

        $githubClient = $this->getMockClient($responses);

        $redisClient = $this->createMock(\Predis\Client::class);

        // will use `setex` & `del` but will be called dynamically by `_call`
        $redisClient->expects($this->once())
            ->method('__call');

        $handler = new StarredReposSyncHandler(
            $doctrine,
            $userRepository,
            $starRepository,
            $repoRepository,
            $githubClient,
            new NullLogger(),
            $redisClient,
            true,
            new Logger('metrics', [$metricsLogHandler = new TestHandler()])
        );

        try {
            $handler->__invoke(new StarredReposSync(123));
        } finally {
            $metrics = $this->assertMetrics($metricsLogHandler, 'failed');
            $this->assertSame(1, $metrics['repo_lookups']);
            $this->assertSame(0, $metrics['flushes']);
            $this->assertSame(0, $metrics['added_stars']);
        }
    }

    /**
     * Everything will goes fine (like testProcessSuccessfulMessage) and we won't remove old stars (no change detected in starred repos).
     */
    public function testProcessSuccessfulMessageNoStarToRemove(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->expects($this->once())
            ->method('isOpen')
            ->willReturn(false); // simulate a closing manager

        $doctrine = $this->createMock(Registry::class);
        $doctrine->expects($this->once())
            ->method('getManager')
            ->willReturn($em);
        $doctrine->expects($this->once())
            ->method('resetManager')
            ->willReturn($em);

        $user = new User();
        $user->setId(123);
        $user->setUsername('bob');
        $user->setName('Bobby');

        $userRepository = $this->createMock(UserRepository::class);

        $userRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->willReturn($user);

        $starRepository = $this->createMock(StarRepository::class);

        $starRepository->expects($this->exactly(2))
            ->method('findAllByUser')
            ->with(123)
            ->willReturn([123]);

        $repo = new Repo();
        $repo->setId(123);
        $repo->setFullName('j0k3r/banditore');
        $repo->setUpdatedAt(new \DateTime()->setTimestamp(time() - 3600 * 72));

        $repoRepository = $this->createMock(RepoRepository::class);

        $repoRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->willReturn($repo);

        $responses = new MockHandler([
            // /rate_limit
            $this->getOKResponse(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 200, 'remaining' => 10]]]),
            // first /user/starred
            $this->getOKResponse([[
                'description' => 'banditore',
                'homepage' => 'http://banditore.io',
                'language' => 'PHP',
                'name' => 'banditore',
                'full_name' => 'j0k3r/banditore',
                'id' => 123,
                'owner' => [
                    'avatar_url' => 'http://avatar.api/banditore.jpg',
                ],
            ]]),
        ]);

        $githubClient = $this->getMockClient($responses);

        $logger = new Logger('foo');
        $logHandler = new TestHandler();
        $logger->pushHandler($logHandler);

        $redisClient = $this->createMock(\Predis\Client::class);

        // will use `setex` & `del` but will be called dynamically by `_call`
        $redisClient->expects($this->exactly(2))
            ->method('__call');

        $handler = new StarredReposSyncHandler(
            $doctrine,
            $userRepository,
            $starRepository,
            $repoRepository,
            $githubClient,
            $logger,
            $redisClient
        );

        $handler->__invoke(new StarredReposSync(123));

        $records = $logHandler->getRecords();

        $this->assertSame('Consume banditore.sync_starred_repos message', $records[0]['message']);
        $this->assertSame('[10] Check <info>bob</info> … ', $records[1]['message']);
        $this->assertSame('    sync 1 starred repos', $records[2]['message']);
        $this->assertSame('Synced repos: 1', $records[3]['message']);
    }

    public function testProcessWithBadClient(): void
    {
        $doctrine = $this->createStub(Registry::class);

        $userRepository = $this->createMock(UserRepository::class);

        $userRepository->expects($this->never())
            ->method('find');

        $starRepository = $this->createMock(StarRepository::class);

        $starRepository->expects($this->never())
            ->method('findAllByUser');

        $repoRepository = $this->createMock(RepoRepository::class);

        $repoRepository->expects($this->never())
            ->method('find');

        $logger = new Logger('foo');
        $logHandler = new TestHandler();
        $logger->pushHandler($logHandler);

        $redisClient = $this->createMock(\Predis\Client::class);

        // will use `setex` & `del` but will be called dynamically by `_call`
        $redisClient->expects($this->never())
            ->method('__call');

        $handler = new StarredReposSyncHandler(
            $doctrine,
            $userRepository,
            $starRepository,
            $repoRepository,
            null, // simulate a bad client
            $logger,
            $redisClient,
            true,
            new Logger('metrics', [$metricsLogHandler = new TestHandler()])
        );

        try {
            $handler->__invoke(new StarredReposSync(123));
            $this->fail('Missing quota must defer the message.');
        } catch (RecoverableMessageHandlingException $e) {
            $this->assertSame(60000, $e->getRetryDelay());
        }
        $metrics = $this->assertMetrics($metricsLogHandler, 'no_client');
        $this->assertSame(0, $metrics['page_requests']);
        $this->assertSame(0, $metrics['rate_limit_checks']);

        $records = $logHandler->getRecords();

        $this->assertSame('No client provided', $records[0]['message']);
    }

    public function testProcessWithRateLimitReached(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->expects($this->never())
            ->method('isOpen');

        $doctrine = $this->createMock(Registry::class);
        $doctrine->expects($this->never())
            ->method('getManager');

        $user = new User();
        $user->setId(123);
        $user->setUsername('bob');
        $user->setName('Bobby');

        $userRepository = $this->createMock(UserRepository::class);

        $userRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->willReturn($user);

        $starRepository = $this->createMock(StarRepository::class);

        $starRepository->expects($this->never())
            ->method('findAllByUser');

        $starRepository->expects($this->never())
            ->method('removeFromUser');

        $repoRepository = $this->createMock(RepoRepository::class);

        $repoRepository->expects($this->never())
            ->method('find');

        $responses = new MockHandler([
            // /rate_limit
            $this->getOKResponse(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 200, 'remaining' => 0]]]),
        ]);

        $githubClient = $this->getMockClient($responses);

        $logger = new Logger('foo');
        $logHandler = new TestHandler();
        $logger->pushHandler($logHandler);

        $redisClient = $this->createMock(\Predis\Client::class);

        // will use `setex` & `del` but will be called dynamically by `_call`
        $redisClient->expects($this->once())
            ->method('__call');

        $handler = new StarredReposSyncHandler(
            $doctrine,
            $userRepository,
            $starRepository,
            $repoRepository,
            $githubClient,
            $logger,
            $redisClient,
            true,
            new Logger('metrics', [$metricsLogHandler = new TestHandler()])
        );

        try {
            $handler->__invoke(new StarredReposSync(123));
            $this->fail('Exhausted quota must defer the message.');
        } catch (RecoverableMessageHandlingException $e) {
            $this->assertGreaterThanOrEqual(999000, $e->getRetryDelay());
            $this->assertLessThanOrEqual(1001000, $e->getRetryDelay());
        }
        $metrics = $this->assertMetrics($metricsLogHandler, 'rate_limit_unavailable');
        $this->assertSame(0, $metrics['page_requests']);
        $this->assertSame(1, $metrics['rate_limit_checks']);

        $records = $logHandler->getRecords();

        $this->assertSame('Consume banditore.sync_starred_repos message', $records[0]['message']);
        $this->assertSame('[0] Check <info>bob</info> … ', $records[1]['message']);
        $this->assertSame('RateLimit reached, deferring sync.', $records[2]['message']);
    }

    public static function rateLimitPages(): iterable
    {
        yield 'first page' => ['first page'];
        yield 'during pagination' => ['pagination'];
        yield 'during account lookup' => ['account lookup'];
        yield 'after account rename' => ['account rename'];
    }

    #[DataProvider('rateLimitPages')]
    public function testRateLimitDuringSyncIsRetriedWithoutRemovingStars(string $stage): void
    {
        $laterPage = 'pagination' === $stage;
        $renamed = 'account rename' === $stage;
        $accountLookup = $renamed || 'account lookup' === $stage;
        $reset = time() + 1000;
        $responses = [$this->getOKResponse(['resources' => ['core' => ['reset' => $reset, 'limit' => 5000, 'remaining' => 10]]])];
        if ($laterPage) {
            $responses[] = $this->getOKResponse([['id' => 666]])->withHeader('Link', '<https://api.github.com/users/bob/starred?page=2&per_page=100>; rel="next"');
        }
        if ($accountLookup) {
            $responses[] = new Response(404, ['Content-Type' => 'application/json'], '{"message":"Not Found"}');
            if ($renamed) {
                $responses[] = $this->getOKResponse(['id' => 123, 'login' => 'new-bob']);
            }
        }
        $responses[] = new Response(403, [
            'Content-Type' => 'application/json',
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Limit' => '5000',
            'X-RateLimit-Reset' => (string) $reset,
        ], json_encode(['message' => 'API rate limit exceeded']));

        $em = $this->createMock(EntityManager::class);
        $em->method('isOpen')->willReturn(true);
        $em->expects($laterPage || $renamed ? $this->once() : $this->never())->method('flush');
        $doctrine = $this->createStub(Registry::class);
        $doctrine->method('getManager')->willReturn($em);

        $user = new User();
        $user->setId(123);
        $user->setUsername('bob');
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('find')->willReturn($user);

        $repo = new Repo();
        $repo->setId(666);
        $repo->setUpdatedAt(new \DateTime());
        $repoRepository = $this->createStub(RepoRepository::class);
        $repoRepository->method('find')->willReturn($repo);
        $starRepository = $this->createMock(StarRepository::class);
        $starRepository->expects($laterPage ? $this->once() : $this->never())->method('findAllByUser')->willReturn([666, 777]);
        $starRepository->expects($this->never())->method('removeFromUser');
        $redis = $this->createMock(\Predis\Client::class);
        $redis->expects($this->once())->method('__call')->with('setex', $this->anything());

        $metricsLogHandler = new TestHandler();
        $handler = new StarredReposSyncHandler($doctrine, $userRepository, $starRepository, $repoRepository, $this->getMockClient(new MockHandler($responses)), new NullLogger(), $redis, true, new Logger('metrics', [$metricsLogHandler]));

        try {
            $handler(new StarredReposSync(123));
            $this->fail('Rate-limited sync must not be acknowledged as successful.');
        } catch (RecoverableMessageHandlingException $e) {
            $this->assertGreaterThanOrEqual(999000, $e->getRetryDelay());
            $this->assertLessThanOrEqual(1001000, $e->getRetryDelay());
        }
        $metrics = $this->assertMetrics($metricsLogHandler, 'rate_limit_unavailable');
        $this->assertSame($laterPage || $renamed ? 2 : 1, $metrics['page_requests']);
        $this->assertSame($accountLookup ? 1 : 0, $metrics['user_requests']);
        $this->assertSame(0, $metrics['removed_stars']);
    }

    public static function recentQuotaCases(): iterable
    {
        yield 'fresh headers avoid quota endpoint' => [5000, 0, false, true];
        yield 'exhausted headers block syncing' => [0, 0, false, false];
        yield 'low quota is checked explicitly' => [199, 0, true, false];
        yield 'expired quota is checked explicitly' => [5000, 30, true, false];
        yield 'missing headers require explicit check' => [null, 0, true, false];
    }

    #[DataProvider('recentQuotaCases')]
    public function testRecentQuotaPreservesTheInitialGuard(?int $remaining, int $elapsed, bool $fallback, bool $allowed): void
    {
        $clock = new MockClock('2026-11-20T13:00:00Z');
        $responses = new MockHandler([
            new Response(200, [
                'X-RateLimit-Resource' => 'core',
                'X-RateLimit-Remaining' => null === $remaining ? '' : (string) $remaining,
                'X-RateLimit-Reset' => (string) ($clock->now()->getTimestamp() + 3600),
            ]),
            ...($fallback ? [$this->getOKResponse(['resources' => ['core' => ['reset' => $clock->now()->getTimestamp() + 3600, 'limit' => 5000, 'remaining' => 0]]])] : []),
            ...($allowed ? [$this->getOKResponse([])] : []),
        ]);
        $trackedClient = new RateLimitClient(new Guzzle7Client(new Client(['handler' => $responses])), $clock);
        $githubClient = new GithubClient(new Builder($trackedClient));
        $trackedClient->sendRequest(new Request('GET', 'https://api.github.com/rate_limit'));
        $clock->sleep($elapsed);
        $user = new User();
        $user->setId(123);
        $user->setUsername('bob');
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('find')->willReturn($user);
        $em = $this->createStub(EntityManager::class);
        $em->method('isOpen')->willReturn(true);
        $doctrine = $this->createMock(Registry::class);
        $doctrine->expects($allowed ? $this->once() : $this->never())->method('getManager')->willReturn($em);
        $starRepository = $this->createStub(StarRepository::class);
        $starRepository->method('findAllByUser')->willReturn([]);
        $metricsLogHandler = new TestHandler();
        $handler = new StarredReposSyncHandler($doctrine, $userRepository, $starRepository, $this->createStub(RepoRepository::class), $githubClient, new NullLogger(), $this->createStub(\Predis\Client::class), true, new Logger('metrics', [$metricsLogHandler]), $trackedClient);

        try {
            $this->assertTrue($handler(new StarredReposSync(123)));
            $this->assertTrue($allowed);
        } catch (RecoverableMessageHandlingException $e) {
            $this->assertFalse($allowed);
            $this->assertGreaterThanOrEqual(60000, $e->getRetryDelay());
        }
        $this->assertCount(0, $responses);
        $metrics = $this->assertMetrics($metricsLogHandler, $allowed ? 'completed' : 'rate_limit_unavailable');
        $this->assertSame($fallback ? 1 : 0, $metrics['rate_limit_checks']);
        $this->assertSame($allowed ? 1 : 0, $metrics['page_requests']);
    }

    public static function accountRecoveries(): iterable
    {
        yield 'renamed account' => [['id' => 123, 'login' => 'new-bob'], 200, true];
        yield 'renamed account but stars still return 404' => [['id' => 123, 'login' => 'new-bob'], 404, true];
        yield 'renamed account but stars return 500' => [['id' => 123, 'login' => 'new-bob'], 500, true];
        yield 'same login is not retried or disabled' => [['id' => 123, 'login' => 'bob'], null, false];
        yield 'mismatched ID is ignored' => [['id' => 456, 'login' => 'new-bob'], null, false];
        yield 'missing login is ignored' => [['id' => 123], null, false];
        yield 'empty login is ignored' => [['id' => 123, 'login' => ''], null, false];
    }

    #[DataProvider('accountRecoveries')]
    public function testAccountRecovery(array $account, ?int $retryStatus, bool $renamed): void
    {
        $user = new User();
        $user->setId(123);
        $user->setUsername('bob');
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('find')->willReturn($user);
        $completed = 200 === $retryStatus;
        $em = $this->createMock(EntityManager::class);
        $em->expects($this->once())->method('isOpen')->willReturn(true);
        $em->expects($renamed ? $this->once() : $this->never())->method('persist')->with($user);
        $em->expects($this->exactly(($renamed ? 1 : 0) + ($completed ? 1 : 0)))->method('flush');
        $doctrine = $this->createStub(Registry::class);
        $doctrine->method('getManager')->willReturn($em);
        $starRepository = $this->createMock(StarRepository::class);
        $starRepository->expects($this->exactly($completed ? 2 : 0))->method('findAllByUser')->willReturn([]);
        $starRepository->expects($this->never())->method('removeFromUser');
        $repoRepository = $this->createMock(RepoRepository::class);
        $repoRepository->expects($this->never())->method('find');
        $responses = new MockHandler([
            $this->getOKResponse(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 5000, 'remaining' => 5000]]]),
            new Response(404, ['Content-Type' => 'application/json']),
            function ($request) use ($account): Response {
                $this->assertSame('/user/123', $request->getUri()->getPath());

                return $this->getOKResponse($account);
            },
            ...(null === $retryStatus ? [] : [function ($request) use ($retryStatus): Response {
                $this->assertSame('/users/new-bob/starred', $request->getUri()->getPath());

                return new Response($retryStatus, ['Content-Type' => 'application/json'], 200 === $retryStatus ? '[]' : '{"message":"Unavailable"}');
            }]),
        ]);
        $metricsLogHandler = new TestHandler();
        $handler = new StarredReposSyncHandler($doctrine, $userRepository, $starRepository, $repoRepository, $this->getMockClient($responses), new NullLogger(), $this->createStub(\Predis\Client::class), true, new Logger('metrics', [$metricsLogHandler]));

        $this->assertTrue($handler(new StarredReposSync(123)));
        $this->assertCount(0, $responses);
        $this->assertNull($user->getRemovedAt());
        $this->assertSame($renamed ? 'new-bob' : 'bob', $user->getUsername());
        $metrics = $this->assertMetrics($metricsLogHandler, $completed ? 'completed' : 'fetch_failed');
        $this->assertSame($user->getUsername(), $metrics['user']);
        $this->assertSame(1, $metrics['user_requests']);
        $this->assertSame($renamed ? 2 : 1, $metrics['page_requests']);
        $this->assertSame(1, $metrics['rate_limit_checks']);
    }

    public static function paginationCases(): iterable
    {
        yield 'fresh first page' => [false, 1];
        yield '304 restores cached first page and its next link' => [true, 1];
        yield 'full last page does not need an empty page' => [false, 100];
    }

    #[DataProvider('paginationCases')]
    public function testKnownFirstPageStillFollowsNextLinkBeforeCleaningStars(bool $cachedFirstPage, int $lastPageSize): void
    {
        $user = new User();
        $user->setId(123);
        $user->setUsername('bob');
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('find')->willReturn($user);
        $em = $this->createMock(EntityManager::class);
        $em->expects($this->once())->method('isOpen')->willReturn(true);
        $em->expects($this->exactly(2))->method('flush');
        $em->expects($this->exactly($lastPageSize))->method('persist')->with($this->callback(static fn ($star): bool => $star instanceof Star && $star->getRepo()->getId() >= 101));
        $doctrine = $this->createStub(Registry::class);
        $doctrine->method('getManager')->willReturn($em);
        $currentStars = [...range(1, 100), 999];
        $starRepository = $this->createMock(StarRepository::class);
        $starRepository->expects($this->exactly(2))->method('findAllByUser')->willReturnOnConsecutiveCalls($currentStars, [...$currentStars, ...range(101, 100 + $lastPageSize)]);
        $starRepository->expects($this->once())->method('removeFromUser')->with([100 => 999], 123);
        $repoRepository = $this->createMock(RepoRepository::class);
        $repoRepository->expects($this->exactly(100 + $lastPageSize))->method('find')->willReturnCallback(static function (int $id): Repo {
            $repo = new Repo();
            $repo->setId($id);
            $repo->setUpdatedAt(new \DateTime());

            return $repo;
        });
        $nextUrl = 'https://api.github.com/users/bob/starred?page=7&per_page=100';
        $firstPage = $this->getOKResponse(array_map(static fn (int $id): array => ['id' => $id], range(1, 100)), ['Link' => '<' . $nextUrl . '>; rel="next"', 'ETag' => '"stars-v1"', 'Cache-Control' => 'max-age=0']);
        $responses = new MockHandler([
            ...($cachedFirstPage ? [$firstPage] : []),
            $this->getOKResponse(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 5000, 'remaining' => 5000]]]),
            $cachedFirstPage ? new Response(304) : $firstPage,
            function ($request) use ($nextUrl, $lastPageSize): Response {
                $this->assertSame($nextUrl, (string) $request->getUri());

                return $this->getOKResponse(array_map(static fn (int $id): array => ['id' => $id], range(101, 100 + $lastPageSize)), ['Link' => '<https://api.github.com/users/bob/starred?page=1>; rel="prev"']);
            },
        ]);
        $githubClient = $this->getMockClient($responses);
        if ($cachedFirstPage) {
            $githubClient->addCache(new ArrayAdapter());
            $githubClient->user()->starred('bob', 1, 100);
        }
        $metricsLogHandler = new TestHandler();
        $handler = new StarredReposSyncHandler($doctrine, $userRepository, $starRepository, $repoRepository, $githubClient, new NullLogger(), $this->createStub(\Predis\Client::class), true, new Logger('metrics', [$metricsLogHandler]));

        $this->assertTrue($handler(new StarredReposSync(123)));
        $this->assertCount(0, $responses);
        $metrics = $this->assertMetrics($metricsLogHandler, 'completed');
        $this->assertSame(2, $metrics['page_requests']);
        $this->assertSame(100 + $lastPageSize, $metrics['stars_seen']);
        $this->assertSame($lastPageSize, $metrics['added_stars']);
        $this->assertSame(1, $metrics['removed_stars']);
    }

    public function testEmptyAccountMetricsResetBetweenMessages(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->expects($this->exactly(2))->method('isOpen')->willReturn(true);
        $em->expects($this->exactly(2))->method('flush');
        $em->expects($this->never())->method('persist');
        $doctrine = $this->createStub(Registry::class);
        $doctrine->method('getManager')->willReturn($em);
        $user = new User();
        $user->setId(123);
        $user->setUsername('bob');
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('find')->willReturn($user);
        $starRepository = $this->createMock(StarRepository::class);
        $starRepository->expects($this->exactly(4))->method('findAllByUser')->willReturnOnConsecutiveCalls([555], [555], [], []);
        $starRepository->expects($this->once())->method('removeFromUser')->with([555], 123);
        $repoRepository = $this->createMock(RepoRepository::class);
        $repoRepository->expects($this->never())->method('find');
        $rateLimit = $this->getOKResponse(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 200, 'remaining' => 10]]]);
        $emptyPage = $this->getOKResponse([]);
        $responses = new MockHandler([$rateLimit, $emptyPage, $rateLimit, $emptyPage]);
        $metricsLogHandler = new TestHandler();
        $handler = new StarredReposSyncHandler($doctrine, $userRepository, $starRepository, $repoRepository, $this->getMockClient($responses), new NullLogger(), $this->createStub(\Predis\Client::class), true, new Logger('metrics', [$metricsLogHandler]));

        foreach ([1, 0] as $removedStars) {
            $metricsLogHandler->clear();
            $this->assertTrue($handler->__invoke(new StarredReposSync(123)));
            $metrics = $this->assertMetrics($metricsLogHandler, 'completed');
            $this->assertSame(1, $metrics['page_requests']);
            $this->assertSame(1, $metrics['rate_limit_checks']);
            $this->assertSame(0, $metrics['stars_seen']);
            $this->assertSame(1, $metrics['flushes']);
            $this->assertSame(0, $metrics['added_stars']);
            $this->assertSame($removedStars, $metrics['removed_stars']);
        }
        $this->assertCount(0, $responses);
    }

    public static function partialFailures(): iterable
    {
        yield 'later page fails' => [false];
        yield 'flush fails' => [true];
    }

    #[DataProvider('partialFailures')]
    public function testMetricsOnPartialImport(bool $failFlush): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->expects($this->once())->method('isOpen')->willReturn(true);
        $em->expects($this->exactly(2))->method('persist');
        $em->expects($this->once())->method('flush')->willReturnCallback(static function () use ($failFlush): void {
            if ($failFlush) {
                throw new \RuntimeException('Flush failed');
            }
        });
        $doctrine = $this->createMock(Registry::class);
        $doctrine->expects($this->once())->method('getManager')->willReturn($em);
        $user = new User();
        $user->setId(123);
        $user->setUsername('bob');
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects($this->once())->method('find')->with(123)->willReturn($user);
        $starRepository = $this->createMock(StarRepository::class);
        $starRepository->expects($this->once())->method('findAllByUser')->with(123)->willReturn([555]);
        $starRepository->expects($this->never())->method('removeFromUser');
        $repoRepository = $this->createMock(RepoRepository::class);
        $repoRepository->expects($this->once())->method('find')->with(666)->willReturn(null);
        $rateLimit = $this->getOKResponse(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 200, 'remaining' => 10]]]);
        $responses = new MockHandler([
            $rateLimit,
            $this->getOKResponse([[
                'id' => 666,
                'name' => 'banditore',
                'full_name' => 'j0k3r/banditore',
                'description' => 'banditore',
                'homepage' => null,
                'language' => 'PHP',
                'owner' => ['avatar_url' => 'https://example.com/avatar.jpg'],
            ]], ['Link' => '<https://api.github.com/users/bob/starred?page=2&per_page=100&sort=created&direction=desc>; rel="next"']),
            new Response(500, ['Content-Type' => 'application/json'], '{"message":"Unavailable"}'),
        ]);
        $redisClient = $this->createMock(\Predis\Client::class);
        $redisClient->expects($this->exactly($failFlush ? 1 : 2))->method('__call');
        $metricsLogHandler = new TestHandler();
        $handler = new StarredReposSyncHandler($doctrine, $userRepository, $starRepository, $repoRepository, $this->getMockClient($responses), new NullLogger(), $redisClient, true, new Logger('metrics', [$metricsLogHandler]));

        if ($failFlush) {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageIsOrContains('Flush failed');
        }
        try {
            $this->assertTrue($handler->__invoke(new StarredReposSync(123)));
        } finally {
            $metrics = $this->assertMetrics($metricsLogHandler, $failFlush ? 'failed' : 'partial');
            $this->assertSame($failFlush ? 1 : 2, $metrics['page_requests']);
            $this->assertSame(1, $metrics['stars_seen']);
            $this->assertSame(1, $metrics['existing_stars']);
            $this->assertSame(1, $metrics['repo_lookups']);
            $this->assertSame($failFlush ? 0 : 1, $metrics['flushes']);
            $this->assertSame($failFlush ? 0 : 1, $metrics['added_stars']);
            $this->assertSame(0, $metrics['removed_stars']);
        }
    }

    public function testConfirmedUnavailableUserIsPersistedAndNotScheduledAgain(): void
    {
        static::ensureKernelShutdown();
        static::createClient();
        $responses = new MockHandler([
            $this->getOKResponse(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 5000, 'remaining' => 5000]]]),
            new Response(404, ['Content-Type' => 'application/json']),
            new Response(404, ['Content-Type' => 'application/json']),
        ]);
        self::getContainer()->set('banditore.client.github.test', $this->getMockClient($responses));
        $repository = self::getContainer()->get(UserRepository::class);
        $em = self::getContainer()->get('doctrine')->getManager();
        $stars = self::getContainer()->get(StarRepository::class)->findAllByUser(123);
        $this->assertContains(123, $repository->findAllToSync());

        try {
            $this->assertTrue(self::getContainer()->get(StarredReposSyncHandler::class)(new StarredReposSync(123)));
            $em->clear();
            $this->assertNotNull($repository->find(123)->getRemovedAt());
            $this->assertNotContains(123, $repository->findAllToSync());
            $this->assertSame($stars, self::getContainer()->get(StarRepository::class)->findAllByUser(123));
            $this->assertCount(0, $responses);
        } finally {
            $repository->find(123)->setRemovedAt(null);
            $em->flush();
        }
    }

    public function testFunctionalConsumer(): void
    {
        $this->restoreFunctionalState();

        $responses = new MockHandler([
            // /rate_limit
            $this->getOKResponse(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 200, 'remaining' => 10]]]),
            // first /user/starred
            $this->getOKResponse([[
                'description' => 'banditore',
                'homepage' => 'http://banditore.io',
                'language' => 'PHP',
                'name' => 'banditore',
                'full_name' => 'j0k3r/banditore',
                'id' => 777,
                'owner' => [
                    'avatar_url' => 'http://avatar.api/banditore.jpg',
                ],
            ]], ['Link' => '<https://api.github.com/users/bob/starred?page=2&per_page=100&sort=created&direction=desc>; rel="next"']),
            // second /user/starred
            $this->getOKResponse([[
                'description' => 'This is a test repo',
                'homepage' => 'http://test.io',
                'language' => 'Ruby',
                'name' => 'test',
                'full_name' => 'test/test',
                'id' => 666,
                'owner' => [
                    'avatar_url' => 'http://0.0.0.0/test.jpg',
                ],
            ]]),
        ]);

        $githubClient = $this->getMockClient($responses);

        $client = static::createClient();

        // override factory to avoid real call to Github
        self::getContainer()->set('banditore.client.github.test', $githubClient);

        $metricsLogHandler = new TestHandler();
        $handler = new StarredReposSyncHandler(
            self::getContainer()->get('doctrine'),
            self::getContainer()->get(UserRepository::class),
            self::getContainer()->get(StarRepository::class),
            self::getContainer()->get(RepoRepository::class),
            $githubClient,
            new NullLogger(),
            self::getContainer()->get('snc_redis.app_cache'),
            true,
            new Logger('metrics', [$metricsLogHandler])
        );

        // before import
        $stars = self::getContainer()->get(StarRepository::class)->findAllByUser(123);
        $this->assertCount(2, $stars, 'User 123 has 2 starred repos');
        $this->assertSame(555, $stars[0], 'User 123 has "symfony/symfony" starred repo');
        $this->assertSame(666, $stars[1], 'User 123 has "test/test" starred repo');

        $handler->__invoke(new StarredReposSync(123));

        /** @var Repo */
        $repo = self::getContainer()->get(RepoRepository::class)->find(777);
        $this->assertNotNull($repo, 'Imported repo with id 777 exists');
        $this->assertSame('j0k3r/banditore', $repo->getFullName(), 'Imported repo with id 777 exists');

        $metrics = $this->assertMetrics($metricsLogHandler, 'completed');
        $this->assertSame(2, $metrics['page_requests']);
        $this->assertSame(2, $metrics['stars_seen']);
        $this->assertSame(2, $metrics['repo_lookups']);
        $this->assertSame(1, $metrics['rate_limit_checks']);
        $this->assertCount(0, $responses);
        $this->assertSame(2, $metrics['flushes']);
        $this->assertSame(1, $metrics['added_stars']);
        $this->assertSame(1, $metrics['removed_stars']);

        // validate that `test/test` association got removed
        $stars = self::getContainer()->get(StarRepository::class)->findAllByUser(123);
        $this->assertCount(2, $stars, 'User 123 has 2 starred repos');
        $this->assertSame(666, $stars[0], 'User 123 has "test/test" starred repo');
        $this->assertSame(777, $stars[1], 'User 123 has "j0k3r/banditore" starred repo');
    }

    private function assertMetrics(TestHandler $logHandler, string $status): array
    {
        $records = $logHandler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('Starred repos sync metrics', $records[0]->message);
        $metrics = $records[0]->context;
        $this->assertSame(123, $metrics['user_id']);
        $this->assertSame($status, $metrics['status']);
        $this->assertSame(['user_lookup', 'rate_limit', 'github_pages', 'github_user', 'existing_stars', 'repo_lookup', 'repo_processing', 'flush', 'cleanup', 'total'], array_keys($metrics['timings_ms']));
        foreach ($metrics['timings_ms'] as $duration) {
            $this->assertGreaterThanOrEqual(0, $duration);
        }
        $timings = $metrics['timings_ms'];
        $total = $timings['total'];
        unset($timings['total']);
        $this->assertGreaterThanOrEqual(array_sum($timings) - 0.01, $total);

        return $metrics;
    }

    private function getOKResponse(array $body, array $headers = []): Response
    {
        return new Response(
            200,
            ['Content-Type' => 'application/json'] + $headers,
            (string) json_encode($body)
        );
    }

    private function restoreFunctionalState(): void
    {
        static::ensureKernelShutdown();
        static::createClient();

        /** @var EntityManager $entityManager */
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        /** @var User $user */
        $user = self::getContainer()->get(UserRepository::class)->find(123);
        /** @var Repo $repoSymfony */
        $repoSymfony = self::getContainer()->get(RepoRepository::class)->find(555);
        /** @var Repo $repoTest */
        $repoTest = self::getContainer()->get(RepoRepository::class)->find(666);

        $entityManager->createQuery('DELETE FROM App\Entity\Star s WHERE s.user = :user')
            ->setParameter('user', $user)
            ->execute();

        $entityManager->createQuery('DELETE FROM App\Entity\Repo r WHERE r.id = :repoId')
            ->setParameter('repoId', 777)
            ->execute();

        $entityManager->persist(new Star($user, $repoSymfony));
        $entityManager->persist(new Star($user, $repoTest));
        $entityManager->flush();
        $entityManager->clear();

        static::ensureKernelShutdown();
    }

    private function getMockClient(MockHandler $responses): GithubClient
    {
        $clientHandler = HandlerStack::create($responses);

        $guzzleClient = new Client([
            'handler' => $clientHandler,
            'http_errors' => false,
        ]);

        $httpClient = new Guzzle7Client($guzzleClient);
        $httpBuilder = new Builder($httpClient);
        $githubClient = new GithubClient($httpBuilder);

        return $githubClient;
    }
}
