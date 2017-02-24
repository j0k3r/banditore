<?php

namespace Tests\AppBundle\Command;

use AppBundle\Command\SyncStarredReposCommand;
use Swarrot\Broker\Message;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class SyncStarredReposCommandTest extends WebTestCase
{
    public function testCommandSyncAllUsersWithoutQueue()
    {
        $client = static::createClient();

        $publisher = $this->getMockBuilder('Swarrot\SwarrotBundle\Broker\Publisher')
            ->disableOriginalConstructor()
            ->getMock();

        $publisher->expects($this->never())
            ->method('publish');

        $syncUser = $this->getMockBuilder('AppBundle\Consumer\SyncStarredRepos')
            ->disableOriginalConstructor()
            ->getMock();

        $syncUser->expects($this->once())
            ->method('process')
            ->with(
                new Message(json_encode(['user_id' => 123])),
                []
            );

        self::$kernel->getContainer()->set('swarrot.publisher', $publisher);
        self::$kernel->getContainer()->set('banditore.consumer.sync_starred_repos', $syncUser);

        $application = new Application($client->getKernel());
        $application->add(new SyncStarredReposCommand());

        $command = $application->find('banditore:sync:starred-repos');

        $tester = new CommandTester($command);
        $tester->execute([
            'command' => $command->getName(),
        ]);

        $this->assertContains('Sync user 123 …', $tester->getDisplay());
    }

    public function testCommandSyncAllUsersWithQueue()
    {
        $client = static::createClient();

        $publisher = $this->getMockBuilder('Swarrot\SwarrotBundle\Broker\Publisher')
            ->disableOriginalConstructor()
            ->getMock();

        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'banditore.sync_starred_repos.publisher',
                new Message(json_encode(['user_id' => 123]))
            );

        $syncUser = $this->getMockBuilder('AppBundle\Consumer\SyncStarredRepos')
            ->disableOriginalConstructor()
            ->getMock();

        $syncUser->expects($this->never())
            ->method('process');

        self::$kernel->getContainer()->set('swarrot.publisher', $publisher);
        self::$kernel->getContainer()->set('banditore.consumer.sync_starred_repos', $syncUser);

        $application = new Application($client->getKernel());
        $application->add(new SyncStarredReposCommand());

        $command = $application->find('banditore:sync:starred-repos');

        $tester = new CommandTester($command);
        $tester->execute([
            'command' => $command->getName(),
            '--use_queue' => true,
        ]);

        $this->assertContains('Sync user 123 …', $tester->getDisplay());
    }

    public function testCommandSyncOneUserById()
    {
        $client = static::createClient();

        $publisher = $this->getMockBuilder('Swarrot\SwarrotBundle\Broker\Publisher')
            ->disableOriginalConstructor()
            ->getMock();

        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'banditore.sync_starred_repos.publisher',
                new Message(json_encode(['user_id' => 123]))
            );

        $syncUser = $this->getMockBuilder('AppBundle\Consumer\SyncStarredRepos')
            ->disableOriginalConstructor()
            ->getMock();

        $syncUser->expects($this->never())
            ->method('process');

        self::$kernel->getContainer()->set('swarrot.publisher', $publisher);
        self::$kernel->getContainer()->set('banditore.consumer.sync_starred_repos', $syncUser);

        $application = new Application($client->getKernel());
        $application->add(new SyncStarredReposCommand());

        $command = $application->find('banditore:sync:starred-repos');

        $tester = new CommandTester($command);
        $tester->execute([
            'command' => $command->getName(),
            '--use_queue' => true,
            '--id' => 123,
        ]);

        $this->assertContains('Sync user 123 …', $tester->getDisplay());
        $this->assertContains('User synced: 1', $tester->getDisplay());
    }

    public function testCommandSyncOneUserByUsername()
    {
        $client = static::createClient();

        $publisher = $this->getMockBuilder('Swarrot\SwarrotBundle\Broker\Publisher')
            ->disableOriginalConstructor()
            ->getMock();

        $publisher->expects($this->never())
            ->method('publish');

        $syncUser = $this->getMockBuilder('AppBundle\Consumer\SyncStarredRepos')
            ->disableOriginalConstructor()
            ->getMock();

        $syncUser->expects($this->once())
            ->method('process')
            ->with(
                new Message(json_encode(['user_id' => 123])),
                []
            );

        self::$kernel->getContainer()->set('swarrot.publisher', $publisher);
        self::$kernel->getContainer()->set('banditore.consumer.sync_starred_repos', $syncUser);

        $application = new Application($client->getKernel());
        $application->add(new SyncStarredReposCommand());

        $command = $application->find('banditore:sync:starred-repos');

        $tester = new CommandTester($command);
        $tester->execute([
            'command' => $command->getName(),
            '--username' => 'admin',
        ]);

        $this->assertContains('Sync user 123 …', $tester->getDisplay());
        $this->assertContains('User synced: 1', $tester->getDisplay());
    }

    public function testCommandSyncOneUserNotFound()
    {
        $client = static::createClient();

        $publisher = $this->getMockBuilder('Swarrot\SwarrotBundle\Broker\Publisher')
            ->disableOriginalConstructor()
            ->getMock();

        $publisher->expects($this->never())
            ->method('publish');

        $syncUser = $this->getMockBuilder('AppBundle\Consumer\SyncStarredRepos')
            ->disableOriginalConstructor()
            ->getMock();

        $syncUser->expects($this->never())
            ->method('process');

        self::$kernel->getContainer()->set('swarrot.publisher', $publisher);
        self::$kernel->getContainer()->set('banditore.consumer.sync_starred_repos', $syncUser);

        $application = new Application($client->getKernel());
        $application->add(new SyncStarredReposCommand());

        $command = $application->find('banditore:sync:starred-repos');

        $tester = new CommandTester($command);
        $tester->execute([
            'command' => $command->getName(),
            '--username' => 'toto',
        ]);

        $this->assertContains('No users found', $tester->getDisplay());
    }
}
