<?php

namespace App\Tests\Command;

use App\Command\SyncVersionsCommand;
use PhpAmqpLib\Message\AMQPMessage;
use Swarrot\Broker\Message;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class SyncVersionsCommandTest extends WebTestCase
{
    public function testCommandSyncAllUsersWithoutQueue()
    {
        $client = static::createClient();

        $publisher = $this->getMockBuilder('Swarrot\SwarrotBundle\Broker\Publisher')
            ->disableOriginalConstructor()
            ->getMock();

        $publisher->expects($this->never())
            ->method('publish');

        $syncVersions = $this->getMockBuilder('App\Consumer\SyncVersions')
            ->disableOriginalConstructor()
            ->getMock();

        $syncVersions->expects($this->any())
            ->method('process');

        $application = new Application($client->getKernel());
        $application->add(new SyncVersionsCommand(
            self::$kernel->getContainer()->get('banditore.repository.repo.test'),
            $publisher,
            $syncVersions,
            self::$kernel->getContainer()->get('swarrot.factory.default')
        ));

        $command = $application->find('banditore:sync:versions');

        $tester = new CommandTester($command);
        $tester->execute([
            'command' => $command->getName(),
        ]);

        $this->assertStringContainsString('Check 555 …', $tester->getDisplay());
    }

    public function testCommandSyncAllUsersWithQueue()
    {
        $client = static::createClient();

        $publisher = $this->getMockBuilder('Swarrot\SwarrotBundle\Broker\Publisher')
            ->disableOriginalConstructor()
            ->getMock();

        $publisher->expects($this->any())
            ->method('publish')
            ->with('banditore.sync_versions.publisher');

        $syncVersions = $this->getMockBuilder('App\Consumer\SyncVersions')
            ->disableOriginalConstructor()
            ->getMock();

        $syncVersions->expects($this->never())
            ->method('process');

        $application = new Application($client->getKernel());
        $application->add(new SyncVersionsCommand(
            self::$kernel->getContainer()->get('banditore.repository.repo.test'),
            $publisher,
            $syncVersions,
            $this->getAmqpMessage(0)
        ));

        $command = $application->find('banditore:sync:versions');

        $tester = new CommandTester($command);
        $tester->execute([
            'command' => $command->getName(),
            '--use_queue' => true,
        ]);

        $this->assertStringContainsString('Check 555 …', $tester->getDisplay());
    }

    public function testCommandSyncAllUsersWithQueueFull()
    {
        $client = static::createClient();

        $publisher = $this->getMockBuilder('Swarrot\SwarrotBundle\Broker\Publisher')
            ->disableOriginalConstructor()
            ->getMock();

        $publisher->expects($this->never())
            ->method('publish');

        $syncVersions = $this->getMockBuilder('App\Consumer\SyncVersions')
            ->disableOriginalConstructor()
            ->getMock();

        $syncVersions->expects($this->never())
            ->method('process');

        $application = new Application($client->getKernel());
        $application->add(new SyncVersionsCommand(
            self::$kernel->getContainer()->get('banditore.repository.repo.test'),
            $publisher,
            $syncVersions,
            $this->getAmqpMessage(10)
        ));

        $command = $application->find('banditore:sync:versions');

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
                'banditore.sync_versions.publisher',
                new Message((string) json_encode(['repo_id' => 555]))
            );

        $syncVersions = $this->getMockBuilder('App\Consumer\SyncVersions')
            ->disableOriginalConstructor()
            ->getMock();

        $syncVersions->expects($this->never())
            ->method('process');

        $application = new Application($client->getKernel());
        $application->add(new SyncVersionsCommand(
            self::$kernel->getContainer()->get('banditore.repository.repo.test'),
            $publisher,
            $syncVersions,
            $this->getAmqpMessage(0)
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

    public function testCommandSyncOneUserByUsername()
    {
        $client = static::createClient();

        $publisher = $this->getMockBuilder('Swarrot\SwarrotBundle\Broker\Publisher')
            ->disableOriginalConstructor()
            ->getMock();

        $publisher->expects($this->never())
            ->method('publish');

        $syncVersions = $this->getMockBuilder('App\Consumer\SyncVersions')
            ->disableOriginalConstructor()
            ->getMock();

        $syncVersions->expects($this->once())
            ->method('process')
            ->with(
                new Message((string) json_encode(['repo_id' => 666])),
                []
            );

        $application = new Application($client->getKernel());
        $application->add(new SyncVersionsCommand(
            self::$kernel->getContainer()->get('banditore.repository.repo.test'),
            $publisher,
            $syncVersions,
            self::$kernel->getContainer()->get('swarrot.factory.default')
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

    public function testCommandSyncOneUserNotFound()
    {
        $client = static::createClient();

        $publisher = $this->getMockBuilder('Swarrot\SwarrotBundle\Broker\Publisher')
            ->disableOriginalConstructor()
            ->getMock();

        $publisher->expects($this->never())
            ->method('publish');

        $syncVersions = $this->getMockBuilder('App\Consumer\SyncVersions')
            ->disableOriginalConstructor()
            ->getMock();

        $syncVersions->expects($this->never())
            ->method('process');

        $application = new Application($client->getKernel());
        $application->add(new SyncVersionsCommand(
            self::$kernel->getContainer()->get('banditore.repository.repo.test'),
            $publisher,
            $syncVersions,
            self::$kernel->getContainer()->get('swarrot.factory.default')
        ));

        $command = $application->find('banditore:sync:versions');

        $tester = new CommandTester($command);
        $tester->execute([
            'command' => $command->getName(),
            '--repo_name' => 'toto',
        ]);

        $this->assertStringContainsString('No repos found', $tester->getDisplay());
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
            ->with('banditore.sync_versions')
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
