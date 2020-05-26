<?php

namespace App\Tests\MessageHandler;

use App\Entity\Repo;
use App\Entity\User;
use App\Message\StarredReposSync;
use App\MessageHandler\StarredReposSyncHandler;
use Github\Client as GithubClient;
use Github\HttpClient\Builder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Http\Adapter\Guzzle6\Client as Guzzle6Client;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class StarredReposSyncHandlerTest extends WebTestCase
{
    public function testProcessNoUser(): void
    {
        $doctrine = $this->getMockBuilder('Doctrine\Bundle\DoctrineBundle\Registry')
            ->disableOriginalConstructor()
            ->getMock();

        $userRepository = $this->getMockBuilder('App\Repository\UserRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $userRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->willReturn(null);

        $starRepository = $this->getMockBuilder('App\Repository\StarRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $repoRepository = $this->getMockBuilder('App\Repository\RepoRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $githubClient = $this->getMockBuilder('Github\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $githubClient->expects($this->never())
            ->method('authenticate');

        $redisClient = $this->getMockBuilder('Predis\Client')
            ->disableOriginalConstructor()
            ->getMock();

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
            $redisClient
        );

        $handler->__invoke(new StarredReposSync(123));
    }

    public function testProcessSuccessfulMessage(): void
    {
        $em = $this->getMockBuilder('Doctrine\ORM\EntityManager')
            ->disableOriginalConstructor()
            ->getMock();
        $em->expects($this->once())
            ->method('isOpen')
            ->willReturn(true);

        $doctrine = $this->getMockBuilder('Doctrine\Bundle\DoctrineBundle\Registry')
            ->disableOriginalConstructor()
            ->getMock();
        $doctrine->expects($this->once())
            ->method('getManager')
            ->willReturn($em);

        $user = new User();
        $user->setId(123);
        $user->setUsername('bob');
        $user->setName('Bobby');

        $userRepository = $this->getMockBuilder('App\Repository\UserRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $userRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->willReturn($user);

        $starRepository = $this->getMockBuilder('App\Repository\StarRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $starRepository->expects($this->exactly(2))
            ->method('findAllByUser')
            ->with(123)
            ->willReturn([666, 777]);

        $starRepository->expects($this->once())
            ->method('removeFromUser')
            ->with([1 => 777], 123)
            ->willReturn(true);

        $repo = new Repo();
        $repo->setId(666);
        $repo->setFullName('j0k3r/banditore');
        $repo->setUpdatedAt((new \DateTime())->setTimestamp(time() - 3600 * 72));

        $repoRepository = $this->getMockBuilder('App\Repository\RepoRepository')
            ->disableOriginalConstructor()
            ->getMock();

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
            // /rate_limit
            $this->getOKResponse(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 200, 'remaining' => 10]]]),
            // third /user/starred will return empty response which means, we reached the last page
            $this->getOKResponse([]),
            // /rate_limit
            $this->getOKResponse(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 200, 'remaining' => 10]]]),
        ]);

        $githubClient = $this->getMockClient($responses);

        $logger = new Logger('foo');
        $logHandler = new TestHandler();
        $logger->pushHandler($logHandler);

        $redisClient = $this->getMockBuilder('Predis\Client')
            ->disableOriginalConstructor()
            ->getMock();

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
        $this->assertSame('Removed stars: 1', $records[3]['message']);
        $this->assertSame('[10] Synced repos: 1', $records[4]['message']);
    }

    public function testUserRemovedFromGitHub(): void
    {
        $em = $this->getMockBuilder('Doctrine\ORM\EntityManager')
            ->disableOriginalConstructor()
            ->getMock();
        $em->expects($this->once())
            ->method('isOpen')
            ->willReturn(true);

        $doctrine = $this->getMockBuilder('Doctrine\Bundle\DoctrineBundle\Registry')
            ->disableOriginalConstructor()
            ->getMock();
        $doctrine->expects($this->once())
            ->method('getManager')
            ->willReturn($em);

        $user = new User();
        $user->setId(123);
        $user->setUsername('bob');
        $user->setName('Bobby');

        $userRepository = $this->getMockBuilder('App\Repository\UserRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $userRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->willReturn($user);

        $starRepository = $this->getMockBuilder('App\Repository\StarRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $starRepository->expects($this->never())
            ->method('findAllByUser');

        $repoRepository = $this->getMockBuilder('App\Repository\RepoRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $repoRepository->expects($this->never())
            ->method('find');

        $responses = new MockHandler([
            // /rate_limit
            $this->getOKResponse(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 200, 'remaining' => 10]]]),
            // first /user/starred
            new Response(404, ['Content-Type' => 'application/json']),
        ]);

        $githubClient = $this->getMockClient($responses);

        $logger = new Logger('foo');
        $logHandler = new TestHandler();
        $logger->pushHandler($logHandler);

        $redisClient = $this->getMockBuilder('Predis\Client')
            ->disableOriginalConstructor()
            ->getMock();

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
        $this->assertStringContainsString('(starred) <error>', $records[2]['message']);

        $this->assertNotNull($user->getRemovedAt());
    }

    public function testProcessUnexpectedError(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('booboo');

        $em = $this->getMockBuilder('Doctrine\ORM\EntityManager')
            ->disableOriginalConstructor()
            ->getMock();
        $em->expects($this->once())
            ->method('isOpen')
            ->willReturn(true);

        $doctrine = $this->getMockBuilder('Doctrine\Bundle\DoctrineBundle\Registry')
            ->disableOriginalConstructor()
            ->getMock();
        $doctrine->expects($this->once())
            ->method('getManager')
            ->willReturn($em);

        $user = new User();
        $user->setId(123);
        $user->setUsername('bob');
        $user->setName('Bobby');

        $userRepository = $this->getMockBuilder('App\Repository\UserRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $userRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->willReturn($user);

        $starRepository = $this->getMockBuilder('App\Repository\StarRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $starRepository->expects($this->once())
            ->method('findAllByUser')
            ->with(123)
            ->willReturn([666]);

        $repoRepository = $this->getMockBuilder('App\Repository\RepoRepository')
            ->disableOriginalConstructor()
            ->getMock();

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
            // /rate_limit
            $this->getOKResponse(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 200, 'remaining' => 10]]]),
            // second /user/starred will return empty response which means, we reached the last page
            $this->getOKResponse([]),
            // /rate_limit
            $this->getOKResponse(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 200, 'remaining' => 10]]]),
        ]);

        $githubClient = $this->getMockClient($responses);

        $redisClient = $this->getMockBuilder('Predis\Client')
            ->disableOriginalConstructor()
            ->getMock();

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
            $redisClient
        );

        $handler->__invoke(new StarredReposSync(123));
    }

    /**
     * Everything will goes fine (like testProcessSuccessfulMessage) and we won't remove old stars (no change detected in starred repos).
     */
    public function testProcessSuccessfulMessageNoStarToRemove(): void
    {
        $em = $this->getMockBuilder('Doctrine\ORM\EntityManager')
            ->disableOriginalConstructor()
            ->getMock();
        $em->expects($this->once())
            ->method('isOpen')
            ->willReturn(false); // simulate a closing manager

        $doctrine = $this->getMockBuilder('Doctrine\Bundle\DoctrineBundle\Registry')
            ->disableOriginalConstructor()
            ->getMock();
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

        $userRepository = $this->getMockBuilder('App\Repository\UserRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $userRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->willReturn($user);

        $starRepository = $this->getMockBuilder('App\Repository\StarRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $starRepository->expects($this->exactly(2))
            ->method('findAllByUser')
            ->with(123)
            ->willReturn([123]);

        $repo = new Repo();
        $repo->setId(123);
        $repo->setFullName('j0k3r/banditore');
        $repo->setUpdatedAt((new \DateTime())->setTimestamp(time() - 3600 * 72));

        $repoRepository = $this->getMockBuilder('App\Repository\RepoRepository')
            ->disableOriginalConstructor()
            ->getMock();

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
            // /rate_limit
            $this->getOKResponse(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 200, 'remaining' => 10]]]),
            // second /user/starred will return empty response which means, we reached the last page
            $this->getOKResponse([]),
            // /rate_limit
            $this->getOKResponse(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 200, 'remaining' => 10]]]),
        ]);

        $githubClient = $this->getMockClient($responses);

        $logger = new Logger('foo');
        $logHandler = new TestHandler();
        $logger->pushHandler($logHandler);

        $redisClient = $this->getMockBuilder('Predis\Client')
            ->disableOriginalConstructor()
            ->getMock();

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
        $this->assertSame('[10] Synced repos: 1', $records[3]['message']);
    }

    public function testProcessWithBadClient(): void
    {
        $doctrine = $this->getMockBuilder('Doctrine\Bundle\DoctrineBundle\Registry')
            ->disableOriginalConstructor()
            ->getMock();

        $userRepository = $this->getMockBuilder('App\Repository\UserRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $userRepository->expects($this->never())
            ->method('find');

        $starRepository = $this->getMockBuilder('App\Repository\StarRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $starRepository->expects($this->never())
            ->method('findAllByUser');

        $repoRepository = $this->getMockBuilder('App\Repository\RepoRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $repoRepository->expects($this->never())
            ->method('find');

        $logger = new Logger('foo');
        $logHandler = new TestHandler();
        $logger->pushHandler($logHandler);

        $redisClient = $this->getMockBuilder('Predis\Client')
            ->disableOriginalConstructor()
            ->getMock();

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
            $redisClient
        );

        $handler->__invoke(new StarredReposSync(123));

        $records = $logHandler->getRecords();

        $this->assertSame('No client provided', $records[0]['message']);
    }

    public function testProcessWithRateLimitReached(): void
    {
        $em = $this->getMockBuilder('Doctrine\ORM\EntityManager')
            ->disableOriginalConstructor()
            ->getMock();
        $em->expects($this->never())
            ->method('isOpen');

        $doctrine = $this->getMockBuilder('Doctrine\Bundle\DoctrineBundle\Registry')
            ->disableOriginalConstructor()
            ->getMock();
        $doctrine->expects($this->never())
            ->method('getManager');

        $user = new User();
        $user->setId(123);
        $user->setUsername('bob');
        $user->setName('Bobby');

        $userRepository = $this->getMockBuilder('App\Repository\UserRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $userRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->willReturn($user);

        $starRepository = $this->getMockBuilder('App\Repository\StarRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $starRepository->expects($this->never())
            ->method('findAllByUser');

        $starRepository->expects($this->never())
            ->method('removeFromUser');

        $repoRepository = $this->getMockBuilder('App\Repository\RepoRepository')
            ->disableOriginalConstructor()
            ->getMock();

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

        $redisClient = $this->getMockBuilder('Predis\Client')
            ->disableOriginalConstructor()
            ->getMock();

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
            $redisClient
        );

        $handler->__invoke(new StarredReposSync(123));

        $records = $logHandler->getRecords();

        $this->assertSame('Consume banditore.sync_starred_repos message', $records[0]['message']);
        $this->assertSame('[0] Check <info>bob</info> … ', $records[1]['message']);
        $this->assertSame('RateLimit reached, stopping.', $records[2]['message']);
    }

    public function testFunctionalConsumer(): void
    {
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
            ]]),
            // /rate_limit
            $this->getOKResponse(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 200, 'remaining' => 10]]]),
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
            // /rate_limit
            $this->getOKResponse(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 200, 'remaining' => 8]]]),
            // third /user/starred will return empty response which means, we reached the last page
            $this->getOKResponse([]),
            // /rate_limit
            $this->getOKResponse(['resources' => ['core' => ['reset' => time() + 1000, 'limit' => 200, 'remaining' => 6]]]),
        ]);

        $githubClient = $this->getMockClient($responses);

        $client = static::createClient();

        // override factory to avoid real call to Github
        self::$container->set('banditore.client.github.test', $githubClient);

        $handler = self::$container->get('banditore.message_handler.sync_starred_repos.test');

        // before import
        $stars = self::$container->get('banditore.repository.star.test')->findAllByUser(123);
        $this->assertCount(2, $stars, 'User 123 has 2 starred repos');
        $this->assertSame(555, $stars[0], 'User 123 has "symfony/symfony" starred repo');
        $this->assertSame(666, $stars[1], 'User 123 has "test/test" starred repo');

        $handler->__invoke(new StarredReposSync(123));

        /** @var Repo */
        $repo = self::$container->get('banditore.repository.repo.test')->find(777);
        $this->assertNotNull($repo, 'Imported repo with id 777 exists');
        $this->assertSame('j0k3r/banditore', $repo->getFullName(), 'Imported repo with id 777 exists');

        // validate that `test/test` association got removed
        $stars = self::$container->get('banditore.repository.star.test')->findAllByUser(123);
        $this->assertCount(2, $stars, 'User 123 has 2 starred repos');
        $this->assertSame(666, $stars[0], 'User 123 has "test/test" starred repo');
        $this->assertSame(777, $stars[1], 'User 123 has "j0k3r/banditore" starred repo');
    }

    private function getOKResponse(array $body): Response
    {
        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            (string) json_encode($body)
        );
    }

    private function getMockClient(MockHandler $responses): GithubClient
    {
        $clientHandler = HandlerStack::create($responses);

        $guzzleClient = new Client([
            'handler' => $clientHandler,
        ]);

        $httpClient = new Guzzle6Client($guzzleClient);
        $httpBuilder = new Builder($httpClient);
        $githubClient = new GithubClient($httpBuilder);

        return $githubClient;
    }
}
