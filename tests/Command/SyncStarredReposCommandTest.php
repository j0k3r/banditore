<?php

namespace App\Tests\Command;

use App\Command\SyncStarredReposCommand;
use PhpAmqpLib\Message\AMQPMessage;
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

        $syncUser = $this->getMockBuilder('App\Consumer\SyncStarredRepos')
            ->disableOriginalConstructor()
            ->getMock();

        $syncUser->expects($this->once())
            ->method('process')
            ->with(
                new Message((string) json_encode(['user_id' => 123])),
                []
            );

        $application = new Application($client->getKernel());
        $application->add(new SyncStarredReposCommand(
            self::$kernel->getContainer()->get('banditore.repository.user.test'),
            $publisher,
            $syncUser,
            self::$kernel->getContainer()->get('swarrot.factory.default')
        ));

        $command = $application->find('banditore:sync:starred-repos');

        $tester = new CommandTester($command);
        $tester->execute([
            'command' => $command->getName(),
        ]);

        $this->assertStringContainsString('Sync user 123 …', $tester->getDisplay());
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
                new Message((string) json_encode(['user_id' => 123]))
            );

        $syncUser = $this->getMockBuilder('App\Consumer\SyncStarredRepos')
            ->disableOriginalConstructor()
            ->getMock();

        $syncUser->expects($this->never())
            ->method('process');

        $application = new Application($client->getKernel());
        $application->add(new SyncStarredReposCommand(
            self::$kernel->getContainer()->get('banditore.repository.user.test'),
            $publisher,
            $syncUser,
            $this->getAmqpMessage(0)
        ));

        $command = $application->find('banditore:sync:starred-repos');

        $tester = new CommandTester($command);
        $tester->execute([
            'command' => $command->getName(),
            '--use_queue' => true,
        ]);

        $this->assertStringContainsString('Sync user 123 …', $tester->getDisplay());
    }

    public function testCommandSyncAllUsersWithQueueFull()
    {
        $client = static::createClient();

        $publisher = $this->getMockBuilder('Swarrot\SwarrotBundle\Broker\Publisher')
            ->disableOriginalConstructor()
            ->getMock();

        $publisher->expects($this->never())
            ->method('publish');

        $syncUser = $this->getMockBuilder('App\Consumer\SyncStarredRepos')
            ->disableOriginalConstructor()
            ->getMock();

        $syncUser->expects($this->never())
            ->method('process');

        $application = new Application($client->getKernel());
        $application->add(new SyncStarredReposCommand(
            self::$kernel->getContainer()->get('banditore.repository.user.test'),
            $publisher,
            $syncUser,
            $this->getAmqpMessage(10)
        ));

        $command = $application->find('banditore:sync:starred-repos');

        $tester = new CommandTester($command);
        $tester->execute([
            'command' => $command->getName(),
            '--use_queue' => true,
        ]);

        $this->assertStringContainsString('Current queue as too much messages (10), skipping.', $tester->getDisplay());
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
                new Message((string) json_encode(['user_id' => 123]))
            );

        $syncUser = $this->getMockBuilder('App\Consumer\SyncStarredRepos')
            ->disableOriginalConstructor()
            ->getMock();

        $syncUser->expects($this->never())
            ->method('process');

        $application = new Application($client->getKernel());
        $application->add(new SyncStarredReposCommand(
            self::$kernel->getContainer()->get('banditore.repository.user.test'),
            $publisher,
            $syncUser,
            $this->getAmqpMessage(0)
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

    public function testCommandSyncOneUserByUsername()
    {
        $client = static::createClient();

        $publisher = $this->getMockBuilder('Swarrot\SwarrotBundle\Broker\Publisher')
            ->disableOriginalConstructor()
            ->getMock();

        $publisher->expects($this->never())
            ->method('publish');

        $syncUser = $this->getMockBuilder('App\Consumer\SyncStarredRepos')
            ->disableOriginalConstructor()
            ->getMock();

        $syncUser->expects($this->once())
            ->method('process')
            ->with(
                new Message((string) json_encode(['user_id' => 123])),
                []
            );

        $application = new Application($client->getKernel());
        $application->add(new SyncStarredReposCommand(
            self::$kernel->getContainer()->get('banditore.repository.user.test'),
            $publisher,
            $syncUser,
            self::$kernel->getContainer()->get('swarrot.factory.default')
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

    public function testCommandSyncOneUserNotFound()
    {
        $client = static::createClient();

        $publisher = $this->getMockBuilder('Swarrot\SwarrotBundle\Broker\Publisher')
            ->disableOriginalConstructor()
            ->getMock();

        $publisher->expects($this->never())
            ->method('publish');

        $syncUser = $this->getMockBuilder('App\Consumer\SyncStarredRepos')
            ->disableOriginalConstructor()
            ->getMock();

        $syncUser->expects($this->never())
            ->method('process');

        $application = new Application($client->getKernel());
        $application->add(new SyncStarredReposCommand(
            self::$kernel->getContainer()->get('banditore.repository.user.test'),
            $publisher,
            $syncUser,
            self::$kernel->getContainer()->get('swarrot.factory.default')
        ));

        $command = $application->find('banditore:sync:starred-repos');

        $tester = new CommandTester($command);
        $tester->execute([
            'command' => $command->getName(),
            '--username' => 'toto',
        ]);

        $this->assertStringContainsString('No users found', $tester->getDisplay());
    }

    private function getAmqpMessage($totalMessage = 0)
    {
        $message = new AMQPMessage();
        $message->delivery_info = [
            'message_count' => $totalMessage,
        ];

        $amqpChannel = $this->getMockBuilder('PhpAmqpLib\Channel\AMQPChannel')
            ->disableOriginalConstructor()
            ->getMock();

        $amqpChannel->expects($this->once())
            ->method('basic_get')
            ->with('banditore.sync_starred_repos')
            ->willReturn($message);

        $amqpLibFactory = $this->getMockBuilder('Swarrot\SwarrotBundle\Broker\AmqpLibFactory')
            ->disableOriginalConstructor()
            ->getMock();

        $amqpLibFactory->expects($this->once())
            ->method('getChannel')
            ->with('rabbitmq')
            ->willReturn($amqpChannel);

        return $amqpLibFactory;
    }
}
