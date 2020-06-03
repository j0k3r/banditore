<?php

namespace App\Tests\Command;

use App\Command\SyncStarredReposCommand;
use App\Message\StarredReposSync;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransport;

class SyncStarredReposCommandTest extends WebTestCase
{
    public function testCommandSyncAllUsersWithoutQueue(): void
    {
        $client = static::createClient();
        $message = new StarredReposSync(123);

        $bus = $this->getMockBuilder('Symfony\Component\Messenger\MessageBusInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $bus->expects($this->never())
            ->method('dispatch');

        $syncRepo = $this->getMockBuilder('App\MessageHandler\StarredReposSyncHandler')
            ->disableOriginalConstructor()
            ->getMock();

        $syncRepo->expects($this->once())
            ->method('__invoke')
            ->with($message);

        $application = new Application($client->getKernel());
        $application->add(new SyncStarredReposCommand(
            self::$kernel->getContainer()->get('banditore.repository.user.test'),
            $syncRepo,
            self::$kernel->getContainer()->get('messenger.transport.sync_starred_repos.test'),
            $bus
        ));

        $command = $application->find('banditore:sync:starred-repos');

        $tester = new CommandTester($command);
        $tester->execute([
            'command' => $command->getName(),
        ]);

        $this->assertStringContainsString('Sync user 123 …', $tester->getDisplay());
    }

    public function testCommandSyncAllUsersWithQueue(): void
    {
        $client = static::createClient();
        $message = new StarredReposSync(123);

        $bus = $this->getMockBuilder('Symfony\Component\Messenger\MessageBusInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $bus->expects($this->once())
            ->method('dispatch')
            ->with($message)
            ->willReturn(new \Symfony\Component\Messenger\Envelope($message));

        $syncRepo = $this->getMockBuilder('App\MessageHandler\StarredReposSyncHandler')
            ->disableOriginalConstructor()
            ->getMock();

        $syncRepo->expects($this->never())
            ->method('__invoke');

        $application = new Application($client->getKernel());
        $application->add(new SyncStarredReposCommand(
            self::$kernel->getContainer()->get('banditore.repository.user.test'),
            $syncRepo,
            $this->getTransportMessageCount(0),
            $bus
        ));

        $command = $application->find('banditore:sync:starred-repos');

        $tester = new CommandTester($command);
        $tester->execute([
            'command' => $command->getName(),
            '--use_queue' => true,
        ]);

        $this->assertStringContainsString('Sync user 123 …', $tester->getDisplay());
    }

    public function testCommandSyncAllUsersWithQueueFull(): void
    {
        $client = static::createClient();

        $bus = $this->getMockBuilder('Symfony\Component\Messenger\MessageBusInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $bus->expects($this->never())
            ->method('dispatch');

        $syncRepo = $this->getMockBuilder('App\MessageHandler\StarredReposSyncHandler')
            ->disableOriginalConstructor()
            ->getMock();

        $syncRepo->expects($this->never())
            ->method('__invoke');

        $application = new Application($client->getKernel());
        $application->add(new SyncStarredReposCommand(
            self::$kernel->getContainer()->get('banditore.repository.user.test'),
            $syncRepo,
            $this->getTransportMessageCount(10),
            $bus
        ));

        $command = $application->find('banditore:sync:starred-repos');

        $tester = new CommandTester($command);
        $tester->execute([
            'command' => $command->getName(),
            '--use_queue' => true,
        ]);

        $this->assertStringContainsString('Current queue as too much messages (10), skipping.', $tester->getDisplay());
    }

    public function testCommandSyncOneUserById(): void
    {
        $client = static::createClient();
        $message = new StarredReposSync(123);

        $bus = $this->getMockBuilder('Symfony\Component\Messenger\MessageBusInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $bus->expects($this->once())
            ->method('dispatch')
            ->with($message)
            ->willReturn(new \Symfony\Component\Messenger\Envelope($message));

        $syncRepo = $this->getMockBuilder('App\MessageHandler\StarredReposSyncHandler')
            ->disableOriginalConstructor()
            ->getMock();

        $syncRepo->expects($this->never())
            ->method('__invoke');

        $application = new Application($client->getKernel());
        $application->add(new SyncStarredReposCommand(
            self::$kernel->getContainer()->get('banditore.repository.user.test'),
            $syncRepo,
            $this->getTransportMessageCount(0),
            $bus
        ));

        $command = $application->find('banditore:sync:starred-repos');

        $tester = new CommandTester($command);
        $tester->execute([
            'command' => $command->getName(),
            '--use_queue' => true,
            '--id' => 123,
        ]);

        $this->assertStringContainsString('Sync user 123 …', $tester->getDisplay());
        $this->assertStringContainsString('User synced: 1', $tester->getDisplay());
    }

    public function testCommandSyncOneUserByUsername(): void
    {
        $client = static::createClient();

        $bus = $this->getMockBuilder('Symfony\Component\Messenger\MessageBusInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $bus->expects($this->never())
            ->method('dispatch');

        $syncRepo = $this->getMockBuilder('App\MessageHandler\StarredReposSyncHandler')
            ->disableOriginalConstructor()
            ->getMock();

        $syncRepo->expects($this->once())
            ->method('__invoke')
            ->with(new StarredReposSync(123));

        $application = new Application($client->getKernel());
        $application->add(new SyncStarredReposCommand(
            self::$kernel->getContainer()->get('banditore.repository.user.test'),
            $syncRepo,
            self::$kernel->getContainer()->get('messenger.transport.sync_starred_repos.test'),
            $bus
        ));

        $command = $application->find('banditore:sync:starred-repos');

        $tester = new CommandTester($command);
        $tester->execute([
            'command' => $command->getName(),
            '--username' => 'admin',
        ]);

        $this->assertStringContainsString('Sync user 123 …', $tester->getDisplay());
        $this->assertStringContainsString('User synced: 1', $tester->getDisplay());
    }

    public function testCommandSyncOneUserNotFound(): void
    {
        $client = static::createClient();

        $bus = $this->getMockBuilder('Symfony\Component\Messenger\MessageBusInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $bus->expects($this->never())
            ->method('dispatch');

        $syncRepo = $this->getMockBuilder('App\MessageHandler\StarredReposSyncHandler')
            ->disableOriginalConstructor()
            ->getMock();

        $syncRepo->expects($this->never())
            ->method('__invoke');

        $application = new Application($client->getKernel());
        $application->add(new SyncStarredReposCommand(
            self::$kernel->getContainer()->get('banditore.repository.user.test'),
            $syncRepo,
            self::$kernel->getContainer()->get('messenger.transport.sync_starred_repos.test'),
            $bus
        ));

        $command = $application->find('banditore:sync:starred-repos');

        $tester = new CommandTester($command);
        $tester->execute([
            'command' => $command->getName(),
            '--username' => 'toto',
        ]);

        $this->assertStringContainsString('No users found', $tester->getDisplay());
    }

    private function getTransportMessageCount(int $totalMessage = 0): AmqpTransport
    {
        $connection = $this->getMockBuilder('Symfony\Component\Messenger\Bridge\Amqp\Transport\Connection')
            ->disableOriginalConstructor()
            ->getMock();

        $connection->expects($this->once())
            ->method('countMessagesInQueues')
            ->willReturn($totalMessage);

        return new AmqpTransport($connection);
    }
}
