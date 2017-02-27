<?php

namespace Tests\AppBundle\Command;

use AppBundle\Command\SyncVersionsCommand;
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

        $syncVersions = $this->getMockBuilder('AppBundle\Consumer\SyncVersions')
            ->disableOriginalConstructor()
            ->getMock();

        $syncVersions->expects($this->any())
            ->method('process');

        self::$kernel->getContainer()->set('swarrot.publisher', $publisher);
        self::$kernel->getContainer()->set('banditore.consumer.sync_versions', $syncVersions);

        $application = new Application($client->getKernel());
        $application->add(new SyncVersionsCommand());

        $command = $application->find('banditore:sync:versions');

        $tester = new CommandTester($command);
        $tester->execute([
            'command' => $command->getName(),
        ]);

        $this->assertContains('Check 555 …', $tester->getDisplay());
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

        $syncVersions = $this->getMockBuilder('AppBundle\Consumer\SyncVersions')
            ->disableOriginalConstructor()
            ->getMock();

        $syncVersions->expects($this->never())
            ->method('process');

        self::$kernel->getContainer()->set('swarrot.publisher', $publisher);
        self::$kernel->getContainer()->set('banditore.consumer.sync_versions', $syncVersions);

        $application = new Application($client->getKernel());
        $application->add(new SyncVersionsCommand());

        $command = $application->find('banditore:sync:versions');

        $tester = new CommandTester($command);
        $tester->execute([
            'command' => $command->getName(),
            '--use_queue' => true,
        ]);

        $this->assertContains('Check 555 …', $tester->getDisplay());
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
                new Message(json_encode(['repo_id' => 555]))
            );

        $syncVersions = $this->getMockBuilder('AppBundle\Consumer\SyncVersions')
            ->disableOriginalConstructor()
            ->getMock();

        $syncVersions->expects($this->never())
            ->method('process');

        self::$kernel->getContainer()->set('swarrot.publisher', $publisher);
        self::$kernel->getContainer()->set('banditore.consumer.sync_versions', $syncVersions);

        $application = new Application($client->getKernel());
        $application->add(new SyncVersionsCommand());

        $command = $application->find('banditore:sync:versions');

        $tester = new CommandTester($command);
        $tester->execute([
            'command' => $command->getName(),
            '--use_queue' => true,
            '--repo_id' => 555,
        ]);

        $this->assertContains('Check 555 …', $tester->getDisplay());
        $this->assertContains('Repo checked: 1', $tester->getDisplay());
    }

    public function testCommandSyncOneUserByUsername()
    {
        $client = static::createClient();

        $publisher = $this->getMockBuilder('Swarrot\SwarrotBundle\Broker\Publisher')
            ->disableOriginalConstructor()
            ->getMock();

        $publisher->expects($this->never())
            ->method('publish');

        $syncVersions = $this->getMockBuilder('AppBundle\Consumer\SyncVersions')
            ->disableOriginalConstructor()
            ->getMock();

        $syncVersions->expects($this->once())
            ->method('process')
            ->with(
                new Message(json_encode(['repo_id' => 666])),
                []
            );

        self::$kernel->getContainer()->set('swarrot.publisher', $publisher);
        self::$kernel->getContainer()->set('banditore.consumer.sync_versions', $syncVersions);

        $application = new Application($client->getKernel());
        $application->add(new SyncVersionsCommand());

        $command = $application->find('banditore:sync:versions');

        $tester = new CommandTester($command);
        $tester->execute([
            'command' => $command->getName(),
            '--repo_name' => 'test/test',
        ]);

        $this->assertContains('Check 666 …', $tester->getDisplay());
        $this->assertContains('Repo checked: 1', $tester->getDisplay());
    }

    public function testCommandSyncOneUserNotFound()
    {
        $client = static::createClient();

        $publisher = $this->getMockBuilder('Swarrot\SwarrotBundle\Broker\Publisher')
            ->disableOriginalConstructor()
            ->getMock();

        $publisher->expects($this->never())
            ->method('publish');

        $syncVersions = $this->getMockBuilder('AppBundle\Consumer\SyncVersions')
            ->disableOriginalConstructor()
            ->getMock();

        $syncVersions->expects($this->never())
            ->method('process');

        self::$kernel->getContainer()->set('swarrot.publisher', $publisher);
        self::$kernel->getContainer()->set('banditore.consumer.sync_versions', $syncVersions);

        $application = new Application($client->getKernel());
        $application->add(new SyncVersionsCommand());

        $command = $application->find('banditore:sync:versions');

        $tester = new CommandTester($command);
        $tester->execute([
            'command' => $command->getName(),
            '--repo_name' => 'toto',
        ]);

        $this->assertContains('No repos found', $tester->getDisplay());
    }
}
