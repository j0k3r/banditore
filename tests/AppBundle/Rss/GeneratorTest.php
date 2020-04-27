<?php

namespace Tests\AppBundle\Rss;

use AppBundle\Entity\User;
use AppBundle\Rss\Generator;

class GeneratorTest extends \PHPUnit\Framework\TestCase
{
    public function test()
    {
        $user = new User();
        $user->setId(123);
        $user->setUsername('bob');
        $user->setName('Bobby');

        $generator = new Generator();
        $channel = $generator->generate(
            $user,
            [
                [
                    'homepage' => 'http://homepa.ge',
                    'language' => 'Thus',
                    'ownerAvatar' => 'http://avat.ar/mine.png',
                    'fullName' => 'test/test',
                    'description' => 'This is an awesome description',
                    'tagName' => '1.0.0',
                    'body' => '<p>yay</p>',
                    'createdAt' => (new \DateTime())->setTimestamp(1171502725),
                ],
            ],
            'http://myfeed.api/.rss'
        );

        $this->assertSame('New releases from starred repo of bob', $channel->getTitle());
        $this->assertSame('http://myfeed.api/.rss', $channel->getLink());
        $this->assertSame('Here are all the new releases from all repos starred by bob', $channel->getDescription());
        $this->assertSame('en', $channel->getLanguage());
        $this->assertStringContainsString('(c)', $channel->getCopyright());
        $this->assertStringContainsString('banditore', $channel->getCopyright());
        $this->assertStringContainsString('15 Feb 2007', $channel->getLastBuildDate()->format('r'));
        $this->assertSame('banditore', $channel->getGenerator());

        $items = $channel->getItems();
        $this->assertCount(1, $items);

        $this->assertSame('test/test 1.0.0', $items[0]->getTitle());
        $this->assertSame('https://github.com/test/test/releases/1.0.0', $items[0]->getLink());
        $this->assertStringContainsString('<img src="http://avat.ar/mine.png&amp;s=140" alt="test/test" title="test/test" />', $items[0]->getDescription());
        $this->assertStringContainsString('#Thus', $items[0]->getDescription());
        $this->assertStringContainsString('<p>yay</p>', $items[0]->getDescription());
        $this->assertStringContainsString('<b><a href="https://github.com/test/test">test/test</a></b>', $items[0]->getDescription());
        $this->assertStringContainsString('(<a href="http://homepa.ge">http://homepa.ge</a>)', $items[0]->getDescription());
        $this->assertStringContainsString('This is an awesome description', $items[0]->getDescription());
        $this->assertSame('https://github.com/test/test/releases/1.0.0', $items[0]->getGuid()->getGuid());
        $this->assertTrue($items[0]->getGuid()->getIsPermaLink());
        $this->assertStringContainsString('15 Feb 2007', $items[0]->getPubDate()->format('r'));
    }
}
