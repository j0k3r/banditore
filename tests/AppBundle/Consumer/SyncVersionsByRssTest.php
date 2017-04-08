<?php

namespace Tests\AppBundle\Consumer;

use AppBundle\Consumer\SyncVersionsRss;
use AppBundle\Entity\Repo;
use AppBundle\Entity\Version;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\NullLogger;
use Swarrot\Broker\Message;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SyncVersionsByRssTest extends WebTestCase
{
    public function testProcessNoRepo()
    {
        $doctrine = $this->getMockBuilder('Doctrine\Bundle\DoctrineBundle\Registry')
            ->disableOriginalConstructor()
            ->getMock();

        $repoRepository = $this->getMockBuilder('AppBundle\Repository\RepoRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $repoRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->willReturn(null);

        $versionRepository = $this->getMockBuilder('AppBundle\Repository\VersionRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $pubsubhubbub = $this->getMockBuilder('AppBundle\PubSubHubbub\Publisher')
            ->disableOriginalConstructor()
            ->getMock();

        $eventDispatcher = $this->getMockBuilder('Symfony\Component\EventDispatcher\EventDispatcher')
            ->setMethods(['dispatch'])
            ->disableOriginalConstructor()
            ->getMock();

        $processor = new SyncVersionsRss(
            $doctrine,
            $repoRepository,
            $versionRepository,
            $pubsubhubbub,
            $eventDispatcher,
            new NullLogger()
        );

        $processor->process(new Message(json_encode(['repo_id' => 123])), []);
    }

    public function testProcessSuccessfulMessage()
    {
        $uow = $this->getMockBuilder('Doctrine\ORM\UnitOfWork')
            ->disableOriginalConstructor()
            ->getMock();
        $uow->expects($this->exactly(2))
            ->method('getScheduledEntityInsertions')
            ->willReturn([]);

        $em = $this->getMockBuilder('Doctrine\ORM\EntityManager')
            ->disableOriginalConstructor()
            ->getMock();
        $em->expects($this->once())
            ->method('isOpen')
            ->willReturn(false); // simulate a closing manager
        $em->expects($this->exactly(2))
            ->method('getUnitOfWork')
            ->willReturn($uow);

        $doctrine = $this->getMockBuilder('Doctrine\Bundle\DoctrineBundle\Registry')
            ->disableOriginalConstructor()
            ->getMock();
        $doctrine->expects($this->once())
            ->method('getManager')
            ->willReturn($em);
        $doctrine->expects($this->once())
            ->method('resetManager')
            ->willReturn($em);

        $repo = new Repo();
        $repo->setId(123);
        $repo->setFullName('bob/wow');
        $repo->setName('wow');

        $repoRepository = $this->getMockBuilder('AppBundle\Repository\RepoRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $repoRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->willReturn($repo);

        $versionRepository = $this->getMockBuilder('AppBundle\Repository\VersionRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $versionRepository->expects($this->exactly(2))
            ->method('findExistingOne')
            ->willReturnCallback(function ($tagName, $repoId) use ($repo) {
                // first version will exist, next one won't
                if ('v1.0.0' === $tagName) {
                    return new Version($repo);
                }
            });

        $eventDispatcher = $this->getMockBuilder('Symfony\Component\EventDispatcher\EventDispatcher')
            ->setMethods(['dispatch'])
            ->disableOriginalConstructor()
            ->getMock();

        $pubsubhubbub = $this->getMockBuilder('AppBundle\PubSubHubbub\Publisher')
            ->disableOriginalConstructor()
            ->getMock();

        $pubsubhubbub->expects($this->once())
            ->method('pingHub')
            ->with([123]);

        $simplePie = $this->getMockBuilder('SimplePie')
            ->disableOriginalConstructor()
            ->getMock();

        $simplePie->expects($this->once())
            ->method('force_feed')
            ->with(true);

        $simplePie->expects($this->once())
            ->method('enable_cache')
            ->with(false);

        $simplePie->expects($this->once())
            ->method('set_feed_url')
            ->with('https://github.com/bob/wow/releases.atom');

        $simplePie->expects($this->once())
            ->method('init');

        $simplePie->expects($this->exactly(2))
            ->method('get_base')
            ->willReturn('https://github.com/symfony/symfony/releases');

        $simplePie->expects($this->exactly(2))
            ->method('sanitize')
            ->willReturnArgument(0);

        $item1 = new \SimplePie_Item($simplePie, [
            'guid' => 'tag:github.com,2008:Repository/123/v1.0.1',
            'title' => 'v1.0.1',
            'updated' => '2020-03-30T17:09:46+02:00',
            'content' => 'blabla',
            'child' => [
                'http://www.w3.org/2005/Atom' => [
                    'link' => [[
                        'attribs' => [
                            '' => [
                                'href' => 'https://github.com/bob/wow/releases/tag/v1.0.1',
                            ],
                        ],
                    ]],
                ],
            ],
        ]);
        $item1->set_registry(new \SimplePie_Registry());

        $item2 = new \SimplePie_Item($simplePie, [
            'guid' => 'tag:github.com,2008:Repository/123/v1.0.0',
            'title' => 'v1.0.0',
            'updated' => '2020-02-30T17:09:46+02:00',
            'content' => 'blabla',
            'child' => [
                'http://www.w3.org/2005/Atom' => [
                    'link' => [[
                        'attribs' => [
                            '' => [
                                'href' => 'https://github.com/bob/wow/releases/tag/v1.0.1',
                            ],
                        ],
                    ]],
                ],
            ],
        ]);
        $item2->set_registry(new \SimplePie_Registry());

        $simplePie->expects($this->once())
            ->method('get_items')
            ->willReturn([$item1, $item2]);

        $logger = new Logger('foo');
        $logHandler = new TestHandler();
        $logger->pushHandler($logHandler);

        $processor = new SyncVersionsRss(
            $doctrine,
            $repoRepository,
            $versionRepository,
            $pubsubhubbub,
            $eventDispatcher,
            $logger
        );
        $processor->setSimplePie($simplePie);

        $processor->process(new Message(json_encode(['repo_id' => 123])), []);

        $records = $logHandler->getRecords();

        $this->assertSame('Consume banditore.sync_versions_rss message', $records[0]['message']);
        $this->assertSame('Check <info>bob/wow</info> … ', $records[1]['message']);
        $this->assertSame('<comment>2</comment> new versions for <info>bob/wow</info>', $records[2]['message']);
    }

    public function testProcessFeedFailed()
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

        $repo = new Repo();
        $repo->setId(123);
        $repo->setFullName('bob/wow');
        $repo->setName('wow');

        $repoRepository = $this->getMockBuilder('AppBundle\Repository\RepoRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $repoRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->willReturn($repo);

        $versionRepository = $this->getMockBuilder('AppBundle\Repository\VersionRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $eventDispatcher = $this->getMockBuilder('Symfony\Component\EventDispatcher\EventDispatcher')
            ->setMethods(['dispatch'])
            ->disableOriginalConstructor()
            ->getMock();

        $pubsubhubbub = $this->getMockBuilder('AppBundle\PubSubHubbub\Publisher')
            ->disableOriginalConstructor()
            ->getMock();

        $simplePie = $this->getMockBuilder('SimplePie')
            ->disableOriginalConstructor()
            ->getMock();

        $simplePie->expects($this->once())
            ->method('force_feed')
            ->with(true);

        $simplePie->expects($this->once())
            ->method('enable_cache')
            ->with(false);

        $simplePie->expects($this->once())
            ->method('set_feed_url')
            ->with('https://github.com/bob/wow/releases.atom');

        $simplePie->expects($this->once())
            ->method('init')
            ->will($this->throwException(new \Exception('oops')));

        $logger = new Logger('foo');
        $logHandler = new TestHandler();
        $logger->pushHandler($logHandler);

        $processor = new SyncVersionsRss(
            $doctrine,
            $repoRepository,
            $versionRepository,
            $pubsubhubbub,
            $eventDispatcher,
            $logger
        );
        $processor->setSimplePie($simplePie);

        $processor->process(new Message(json_encode(['repo_id' => 123])), []);

        $records = $logHandler->getRecords();

        $this->assertSame('Consume banditore.sync_versions_rss message', $records[0]['message']);
        $this->assertSame('Check <info>bob/wow</info> … ', $records[1]['message']);
        $this->assertSame('(simplePie/init) <error>oops</error>', $records[2]['message']);
    }

    public function testProcessNoItemInFeed()
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

        $repo = new Repo();
        $repo->setId(123);
        $repo->setFullName('bob/wow');
        $repo->setName('wow');

        $repoRepository = $this->getMockBuilder('AppBundle\Repository\RepoRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $repoRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->willReturn($repo);

        $versionRepository = $this->getMockBuilder('AppBundle\Repository\VersionRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $eventDispatcher = $this->getMockBuilder('Symfony\Component\EventDispatcher\EventDispatcher')
            ->setMethods(['dispatch'])
            ->disableOriginalConstructor()
            ->getMock();

        $pubsubhubbub = $this->getMockBuilder('AppBundle\PubSubHubbub\Publisher')
            ->disableOriginalConstructor()
            ->getMock();

        $simplePie = $this->getMockBuilder('SimplePie')
            ->disableOriginalConstructor()
            ->getMock();

        $simplePie->expects($this->once())
            ->method('force_feed')
            ->with(true);

        $simplePie->expects($this->once())
            ->method('enable_cache')
            ->with(false);

        $simplePie->expects($this->once())
            ->method('set_feed_url')
            ->with('https://github.com/bob/wow/releases.atom');

        $simplePie->expects($this->once())
            ->method('init');

        $simplePie->expects($this->once())
            ->method('get_items')
            ->willReturn([]);

        $logger = new Logger('foo');
        $logHandler = new TestHandler();
        $logger->pushHandler($logHandler);

        $processor = new SyncVersionsRss(
            $doctrine,
            $repoRepository,
            $versionRepository,
            $pubsubhubbub,
            $eventDispatcher,
            $logger
        );
        $processor->setSimplePie($simplePie);

        $processor->process(new Message(json_encode(['repo_id' => 123])), []);

        $records = $logHandler->getRecords();

        $this->assertSame('Consume banditore.sync_versions_rss message', $records[0]['message']);
        $this->assertSame('Check <info>bob/wow</info> … ', $records[1]['message']);
        $this->assertSame('<comment>0</comment> new versions for <info>bob/wow</info>', $records[2]['message']);
    }
}
