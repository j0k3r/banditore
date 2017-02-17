<?php

namespace Tests\AppBundle\Webfeeds;

use AppBundle\Webfeeds\Webfeeds;
use AppBundle\Webfeeds\WebfeedsWriter;
use MarcW\RssWriter\RssWriter;

class WebfeedsWriterTest extends \PHPUnit_Framework_TestCase
{
    public function test()
    {
        $writer = new WebfeedsWriter();
        $rssWriter = new RssWriter();

        $webfeeds = new Webfeeds();
        $webfeeds->setLogo('https://upload.wikimedia.org/wikipedia/commons/a/ab/Logo_TV_2015.png')
            ->setIcon('https://upload.wikimedia.org/wikipedia/commons/a/ab/Logo_TV_2015.png')
            ->setAccentColor('404040');

        $writer->write($rssWriter, $webfeeds);

        $expected = <<<'EOF'
<webfeeds:logo>https://upload.wikimedia.org/wikipedia/commons/a/ab/Logo_TV_2015.png</webfeeds:logo><webfeeds:icon>https://upload.wikimedia.org/wikipedia/commons/a/ab/Logo_TV_2015.png</webfeeds:icon><webfeeds:accentColor>404040</webfeeds:accentColor>
EOF
        ;

        $this->assertSame(
            $expected,
            $rssWriter->getXmlWriter()->flush()
        );
    }
}
