<?php

namespace App\Tests\Command;

use App\Command\SyncVersionsCommand;
use App\Message\VersionsSync;
use App\MessageHandler\VersionsSyncHandler;
use App\Repository\RepoRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransport;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\Connection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class SyncVersionsCommandTest extends KernelTestCase
{
    public function testCommandSyncAllUsersWithoutQueue(): void
    {
        $bus = $this->getMockBuilder(MessageBusInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $bus->expects($this->never())
            ->method('dispatch');

        $syncVersion = $this->getMockBuilder(VersionsSyncHandler::class)
            ->disableOriginalConstructor()
            ->getMock();

        $syncVersion->expects($this->any())
            ->method('__invoke');

        $command = new SyncVersionsCommand(
            self::getContainer()->get(RepoRepository::class),
            $syncVersion,
            self::getContainer()->get('messenger.transport.sync_versions'),
            $bus
        );

        $output = new BufferedOutput();

        $res = $command->__invoke($output, false, false, false);

        $this->assertSame($res, 0);
        $this->assertStringContainsString('Check 555 …', $output->fetch());
    }

    public function testCommandSyncAllUsersWithQueue(): void
    {
        $bus = $this->getMockBuilder(MessageBusInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $bus->expects($this->any())
            ->method('dispatch')
            ->willReturn(new Envelope(new VersionsSync(555)));

        $syncVersion = $this->getMockBuilder(VersionsSyncHandler::class)
            ->disableOriginalConstructor()
            ->getMock();

        $syncVersion->expects($this->any())
            ->method('__invoke');

        $command = new SyncVersionsCommand(
            self::getContainer()->get(RepoRepository::class),
            $syncVersion,
            $this->getTransportMessageCount(0),
            $bus
        );

        $output = new BufferedOutput();

        $res = $command->__invoke($output, false, false, true);

        $this->assertSame($res, 0);
        $this->assertStringContainsString('Check 555 …', $output->fetch());
    }

    public function testCommandSyncAllUsersWithQueueFull(): void
    {
        $bus = $this->getMockBuilder(MessageBusInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $bus->expects($this->never())
            ->method('dispatch');

        $syncVersion = $this->getMockBuilder(VersionsSyncHandler::class)
            ->disableOriginalConstructor()
            ->getMock();

        $syncVersion->expects($this->never())
            ->method('__invoke');

        $command = new SyncVersionsCommand(
            self::getContainer()->get(RepoRepository::class),
            $syncVersion,
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
        $message = new VersionsSync(555);

        $bus = $this->getMockBuilder(MessageBusInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $bus->expects($this->once())
            ->method('dispatch')
            ->with($message)
            ->willReturn(new Envelope($message));

        $syncVersion = $this->getMockBuilder(VersionsSyncHandler::class)
            ->disableOriginalConstructor()
            ->getMock();

        $syncVersion->expects($this->never())
            ->method('__invoke');

        $command = new SyncVersionsCommand(
            self::getContainer()->get(RepoRepository::class),
            $syncVersion,
            $this->getTransportMessageCount(0),
            $bus
        );

        $output = new BufferedOutput();

        $res = $command->__invoke($output, '555', false, true);

        $this->assertSame($res, 0);

        $buffer = $output->fetch();
        $this->assertStringContainsString('Check 555 …', $buffer);
        $this->assertStringContainsString('Repo checked: 1', $buffer);
    }

    public function testCommandSyncOneUserByUsername(): void
    {
        $message = new VersionsSync(666);

        $bus = $this->getMockBuilder(MessageBusInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $bus->expects($this->never())
            ->method('dispatch');

        $syncVersion = $this->getMockBuilder(VersionsSyncHandler::class)
            ->disableOriginalConstructor()
            ->getMock();

        $syncVersion->expects($this->once())
            ->method('__invoke')
            ->with($message);

        $command = new SyncVersionsCommand(
            self::getContainer()->get(RepoRepository::class),
            $syncVersion,
            self::getContainer()->get('messenger.transport.sync_versions'),
            $bus
        );

        $output = new BufferedOutput();

        $res = $command->__invoke($output, false, 'test/test', false);

        $this->assertSame($res, 0);

        $buffer = $output->fetch();
        $this->assertStringContainsString('Check 666 …', $buffer);
        $this->assertStringContainsString('Repo checked: 1', $buffer);
    }

    public function testCommandSyncOneUserNotFound(): void
    {
        $bus = $this->getMockBuilder(MessageBusInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $bus->expects($this->never())
            ->method('dispatch');

        $syncVersion = $this->getMockBuilder(VersionsSyncHandler::class)
            ->disableOriginalConstructor()
            ->getMock();

        $syncVersion->expects($this->never())
            ->method('__invoke');

        $command = new SyncVersionsCommand(
            self::getContainer()->get(RepoRepository::class),
            $syncVersion,
            self::getContainer()->get('messenger.transport.sync_versions'),
            $bus
        );

        $output = new BufferedOutput();

        $res = $command->__invoke($output, false, 'toto', false);

        $this->assertSame($res, 1);
        $this->assertStringContainsString('No repos found', $output->fetch());
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
