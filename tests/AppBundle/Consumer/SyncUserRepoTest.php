<?php

namespace Tests\AppBundle\Consumer;

use AppBundle\Consumer\SyncUserRepo;
use AppBundle\Entity\User;
use Github\Api\CurrentUser;
use Github\Api\RateLimit;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\NullLogger;
use Swarrot\Broker\Message;

class SyncUserRepoTest extends \PHPUnit_Framework_TestCase
{
    public function testProcessNoUser()
    {
        $em = $this->getMockBuilder('Doctrine\ORM\EntityManager')
            ->disableOriginalConstructor()
            ->getMock();

        $userRepository = $this->getMockBuilder('AppBundle\Repository\UserRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $userRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->willReturn(null);

        $starRepository = $this->getMockBuilder('AppBundle\Repository\StarRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $repoRepository = $this->getMockBuilder('AppBundle\Repository\RepoRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $githubClient = $this->getMockBuilder('Github\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $githubClient->expects($this->never())
            ->method('authenticate');

        $processor = new SyncUserRepo(
            $em,
            $userRepository,
            $starRepository,
            $repoRepository,
            new NullLogger()
        );
        $processor->setClient($githubClient);

        $processor->process(new Message(json_encode(['user_id' => 123])), []);
    }

    public function testProcessSuccessfulMessage()
    {
        $em = $this->getMockBuilder('Doctrine\ORM\EntityManager')
            ->disableOriginalConstructor()
            ->getMock();

        $user = new User();
        $user->setId(123);
        $user->setUsername('bob');
        $user->setName('Bobby');

        $userRepository = $this->getMockBuilder('AppBundle\Repository\UserRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $userRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->will($this->returnValue($user));

        $starRepository = $this->getMockBuilder('AppBundle\Repository\StarRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $starRepository->expects($this->once())
            ->method('findAllByUser')
            ->with(123)
            ->willReturn(['removed/star-repo']);

        $star = $this->getMockBuilder('AppBundle\Entity\Star')
            ->disableOriginalConstructor()
            ->getMock();

        $star->expects($this->once())
            ->method('getId')
            ->with()
            ->willReturn(1);

        $repoRepository = $this->getMockBuilder('AppBundle\Repository\RepoRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $repoRepository->expects($this->once())
            ->method('findOneBy')
            ->with(['fullName' => 'removed/star-repo'])
            ->willReturn($star);

        $mock = new MockHandler([
            // first /user/starred
            new Response(200, ['Content-Type' => 'application/json'], json_encode([[
                'description' => 'banditore',
                'name' => 'banditore',
                'full_name' => 'j0k3r/banditore',
                'id' => 666,
                'owner' => [
                    'avatar_url' => 'http://avatar.api/banditore.jpg',
                ],
            ]])),
            // /rate_limit
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => 10]]])),
            // second /user/starred
            new Response(200, ['Content-Type' => 'application/json'], json_encode([])),
        ]);

        $clientHandler = HandlerStack::create($mock);
        $guzzleClient = new Client([
            'base_uri' => 'https://github.api',
            'handler' => $clientHandler,
        ]);

        $githubClient = $this->getMockBuilder('Github\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $githubUser = new CurrentUser($githubClient);
        $githubRate = new RateLimit($githubClient);

        $githubClient->expects($this->any())
            ->method('api')
            ->will($this->returnCallback(function ($arg) use ($githubUser, $githubRate) {
                switch ($arg) {
                    case 'current_user':
                        return $githubUser;
                    case 'rate_limit':
                        return $githubRate;
                }
            }));

        $githubClient->expects($this->any())
            ->method('getHttpClient')
            ->will($this->returnValue($guzzleClient));

        $logger = new Logger('foo');
        $logHandler = new TestHandler();
        $logger->pushHandler($logHandler);

        $processor = new SyncUserRepo(
            $em,
            $userRepository,
            $starRepository,
            $repoRepository,
            $logger
        );
        $processor->setClient($githubClient);

        $processor->process(new Message(json_encode(['user_id' => 123])), []);

        $records = $logHandler->getRecords();

        $this->assertSame('Consume banditore.sync_user_repo message', $records[0]['message']);
        $this->assertSame('    sync 1 starred repos', $records[1]['message']);
        $this->assertSame('Removed stars: 1', $records[2]['message']);
        $this->assertSame('Synced repos: 1', $records[3]['message']);
    }
}
