<?php

namespace App\Tests\Command;

use App\Command\SyncStarredReposCommand;
use App\Message\StarredReposSync;
use App\MessageHandler\StarredReposSyncHandler;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransport;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\Connection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class SyncStarredReposCommandTest extends KernelTestCase
{
    public function testCommandSyncAllUsersWithoutQueue(): void
    {
        $message = new StarredReposSync(123);

        $bus = $this->getMockBuilder(MessageBusInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $bus->expects($this->never())
            ->method('dispatch');

        $syncRepo = $this->getMockBuilder(StarredReposSyncHandler::class)
            ->disableOriginalConstructor()
            ->getMock();

        $syncRepo->expects($this->once())
            ->method('__invoke')
            ->with($message);

        $command = new SyncStarredReposCommand(
            self::getContainer()->get(UserRepository::class),
            $syncRepo,
            self::getContainer()->get('messenger.transport.sync_starred_repos'),
            $bus
        );

        $output = new BufferedOutput();

        $res = $command->__invoke($output, false, false, false);

        $this->assertSame($res, 0);
        $this->assertStringContainsString('Sync user 123 …', $output->fetch());
    }

    public function testCommandSyncAllUsersWithQueue(): void
    {
        $message = new StarredReposSync(123);

        $bus = $this->getMockBuilder(MessageBusInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $bus->expects($this->once())
            ->method('dispatch')
            ->with($message)
            ->willReturn(new Envelope($message));

        $syncRepo = $this->getMockBuilder(StarredReposSyncHandler::class)
            ->disableOriginalConstructor()
            ->getMock();

        $syncRepo->expects($this->never())
            ->method('__invoke');

        $command = new SyncStarredReposCommand(
            self::getContainer()->get(UserRepository::class),
            $syncRepo,
            $this->getTransportMessageCount(0),
            $bus
        );

        $output = new BufferedOutput();

        $res = $command->__invoke($output, false, false, true);

        $this->assertSame($res, 0);
        $this->assertStringContainsString('Sync user 123 …', $output->fetch());
    }

    public function testCommandSyncAllUsersWithQueueFull(): void
    {
        $bus = $this->getMockBuilder(MessageBusInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $bus->expects($this->never())
            ->method('dispatch');

        $syncRepo = $this->getMockBuilder(StarredReposSyncHandler::class)
            ->disableOriginalConstructor()
            ->getMock();

        $syncRepo->expects($this->never())
            ->method('__invoke');

        $command = new SyncStarredReposCommand(
            self::getContainer()->get(UserRepository::class),
            $syncRepo,
            $this->getTransportMessageCount(10),
            $bus
        );

        $output = new BufferedOutput();

        $res = $command->__invoke($output, false, false, true);

        $this->assertSame($res, 1);
        $this->assertStringContainsString('Current queue as too much messages (10), skipping.', $output->fetch());
    }

    public function testCommandSyncOneUserById(): void
    {
        $message = new StarredReposSync(123);

        $bus = $this->getMockBuilder(MessageBusInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $bus->expects($this->once())
            ->method('dispatch')
            ->with($message)
            ->willReturn(new Envelope($message));

        $syncRepo = $this->getMockBuilder(StarredReposSyncHandler::class)
            ->disableOriginalConstructor()
            ->getMock();

        $syncRepo->expects($this->never())
            ->method('__invoke');

        $command = new SyncStarredReposCommand(
            self::getContainer()->get(UserRepository::class),
            $syncRepo,
            $this->getTransportMessageCount(0),
            $bus
        );

        $output = new BufferedOutput();

        $res = $command->__invoke($output, '123', false, true);

        $this->assertSame($res, 0);

        $buffer = $output->fetch();
        $this->assertStringContainsString('Sync user 123 …', $buffer);
        $this->assertStringContainsString('User synced: 1', $buffer);
    }

    public function testCommandSyncOneUserByUsername(): void
    {
        $bus = $this->getMockBuilder(MessageBusInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $bus->expects($this->never())
            ->method('dispatch');

        $syncRepo = $this->getMockBuilder(StarredReposSyncHandler::class)
            ->disableOriginalConstructor()
            ->getMock();

        $syncRepo->expects($this->once())
            ->method('__invoke')
            ->with(new StarredReposSync(123));

        $command = new SyncStarredReposCommand(
            self::getContainer()->get(UserRepository::class),
            $syncRepo,
            self::getContainer()->get('messenger.transport.sync_starred_repos'),
            $bus
        );

        $output = new BufferedOutput();

        $res = $command->__invoke($output, false, 'admin', false);

        $this->assertSame($res, 0);

        $buffer = $output->fetch();
        $this->assertStringContainsString('Sync user 123 …', $buffer);
        $this->assertStringContainsString('User synced: 1', $buffer);
    }

    public function testCommandSyncOneUserNotFound(): void
    {
        $bus = $this->getMockBuilder(MessageBusInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $bus->expects($this->never())
            ->method('dispatch');

        $syncRepo = $this->getMockBuilder(StarredReposSyncHandler::class)
            ->disableOriginalConstructor()
            ->getMock();

        $syncRepo->expects($this->never())
            ->method('__invoke');

        $command = new SyncStarredReposCommand(
            self::getContainer()->get(UserRepository::class),
            $syncRepo,
            self::getContainer()->get('messenger.transport.sync_starred_repos'),
            $bus
        );

        $output = new BufferedOutput();

        $res = $command->__invoke($output, false, 'toto', false);

        $this->assertSame($res, 1);
        $this->assertStringContainsString('No users found', $output->fetch());
    }

    private function getTransportMessageCount(int $totalMessage = 0): AmqpTransport
    {
        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->getMock();

        $connection->expects($this->once())
            ->method('countMessagesInQueues')
            ->willReturn($totalMessage);

        return new AmqpTransport($connection);
    }
}
