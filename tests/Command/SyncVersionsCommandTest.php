<?php

namespace App\Tests\Command;

use App\Command\SyncVersionsCommand;
use App\Message\VersionsSync;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\AmqpExt\AmqpTransport;

class SyncVersionsCommandTest extends WebTestCase
{
    public function testCommandSyncAllUsersWithoutQueue(): void
    {
        $client = static::createClient();

        $bus = $this->getMockBuilder('Symfony\Component\Messenger\MessageBusInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $bus->expects($this->never())
            ->method('dispatch');

        $syncVersion = $this->getMockBuilder('App\MessageHandler\VersionsSyncHandler')
            ->disableOriginalConstructor()
            ->getMock();

        $syncVersion->expects($this->any())
            ->method('__invoke');

        $application = new Application($client->getKernel());
        $application->add(new SyncVersionsCommand(
            self::$kernel->getContainer()->get('banditore.repository.repo.test'),
            $syncVersion,
            self::$kernel->getContainer()->get('messenger.transport.sync_versions.test'),
            $bus
        ));

        $command = $application->find('banditore:sync:versions');

        $tester = new CommandTester($command);
        $tester->execute([
            'command' => $command->getName(),
        ]);

        $this->assertStringContainsString('Check 555 …', $tester->getDisplay());
    }

    public function testCommandSyncAllUsersWithQueue(): void
    {
        $client = static::createClient();

        $bus = $this->getMockBuilder('Symfony\Component\Messenger\MessageBusInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $bus->expects($this->any())
            ->method('dispatch')
            ->willReturn(new Envelope(new VersionsSync(555)));

        $syncVersion = $this->getMockBuilder('App\MessageHandler\VersionsSyncHandler')
            ->disableOriginalConstructor()
            ->getMock();

        $syncVersion->expects($this->any())
            ->method('__invoke');

        $application = new Application($client->getKernel());
        $application->add(new SyncVersionsCommand(
            self::$kernel->getContainer()->get('banditore.repository.repo.test'),
            $syncVersion,
            $this->getTransportMessageCount(0),
            $bus
        ));

        $command = $application->find('banditore:sync:versions');

        $tester = new CommandTester($command);
        $tester->execute([
            'command' => $command->getName(),
            '--use_queue' => true,
        ]);

        $this->assertStringContainsString('Check 555 …', $tester->getDisplay());
    }

    public function testCommandSyncAllUsersWithQueueFull(): void
    {
        $client = static::createClient();

        $bus = $this->getMockBuilder('Symfony\Component\Messenger\MessageBusInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $bus->expects($this->never())
            ->method('dispatch');

        $syncVersion = $this->getMockBuilder('App\MessageHandler\VersionsSyncHandler')
            ->disableOriginalConstructor()
            ->getMock();

        $syncVersion->expects($this->never())
            ->method('__invoke');

        $application = new Application($client->getKernel());
        $application->add(new SyncVersionsCommand(
            self::$kernel->getContainer()->get('banditore.repository.repo.test'),
            $syncVersion,
            $this->getTransportMessageCount(10),
            $bus
        ));

        $command = $application->find('banditore:sync:versions');

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
        $message = new VersionsSync(555);

        $bus = $this->getMockBuilder('Symfony\Component\Messenger\MessageBusInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $bus->expects($this->once())
            ->method('dispatch')
            ->with($message)
            ->willReturn(new Envelope($message));

        $syncVersion = $this->getMockBuilder('App\MessageHandler\VersionsSyncHandler')
            ->disableOriginalConstructor()
            ->getMock();

        $syncVersion->expects($this->never())
            ->method('__invoke');

        $application = new Application($client->getKernel());
        $application->add(new SyncVersionsCommand(
            self::$kernel->getContainer()->get('banditore.repository.repo.test'),
            $syncVersion,
            $this->getTransportMessageCount(0),
            $bus
        ));

        $command = $application->find('banditore:sync:versions');

        $tester = new CommandTester($command);
        $tester->execute([
            'command' => $command->getName(),
            '--use_queue' => true,
            '--repo_id' => 555,
        ]);

        $this->assertStringContainsString('Check 555 …', $tester->getDisplay());
        $this->assertStringContainsString('Repo checked: 1', $tester->getDisplay());
    }

    public function testCommandSyncOneUserByUsername(): void
    {
        $client = static::createClient();
        $message = new VersionsSync(666);

        $bus = $this->getMockBuilder('Symfony\Component\Messenger\MessageBusInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $bus->expects($this->never())
            ->method('dispatch');

        $syncVersion = $this->getMockBuilder('App\MessageHandler\VersionsSyncHandler')
            ->disableOriginalConstructor()
            ->getMock();

        $syncVersion->expects($this->once())
            ->method('__invoke')
            ->with($message);

        $application = new Application($client->getKernel());
        $application->add(new SyncVersionsCommand(
            self::$kernel->getContainer()->get('banditore.repository.repo.test'),
            $syncVersion,
            self::$kernel->getContainer()->get('messenger.transport.sync_versions.test'),
            $bus
        ));

        $command = $application->find('banditore:sync:versions');

        $tester = new CommandTester($command);
        $tester->execute([
            'command' => $command->getName(),
            '--repo_name' => 'test/test',
        ]);

        $this->assertStringContainsString('Check 666 …', $tester->getDisplay());
        $this->assertStringContainsString('Repo checked: 1', $tester->getDisplay());
    }

    public function testCommandSyncOneUserNotFound(): void
    {
        $client = static::createClient();

        $bus = $this->getMockBuilder('Symfony\Component\Messenger\MessageBusInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $bus->expects($this->never())
            ->method('dispatch');

        $syncVersion = $this->getMockBuilder('App\MessageHandler\VersionsSyncHandler')
            ->disableOriginalConstructor()
            ->getMock();

        $syncVersion->expects($this->never())
            ->method('__invoke');

        $application = new Application($client->getKernel());
        $application->add(new SyncVersionsCommand(
            self::$kernel->getContainer()->get('banditore.repository.repo.test'),
            $syncVersion,
            self::$kernel->getContainer()->get('messenger.transport.sync_versions.test'),
            $bus
        ));

        $command = $application->find('banditore:sync:versions');

        $tester = new CommandTester($command);
        $tester->execute([
            'command' => $command->getName(),
            '--repo_name' => 'toto',
        ]);

        $this->assertStringContainsString('No repos found', $tester->getDisplay());
    }

    private function getTransportMessageCount(int $totalMessage = 0): AmqpTransport
    {
        $connection = $this->getMockBuilder('Symfony\Component\Messenger\Transport\AmqpExt\Connection')
            ->disableOriginalConstructor()
            ->getMock();

        $connection->expects($this->once())
            ->method('countMessagesInQueues')
            ->willReturn($totalMessage);

        return new AmqpTransport($connection);
    }
}
