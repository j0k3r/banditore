<?php

namespace App\Webfeeds;

use MarcW\RssWriter\RssWriter;
use MarcW\RssWriter\WriterRegistererInterface;

/**
 * WebfeedsWriter.
 *
 * Mostly used (or handled) by Feedly.
 *
 * @see https://blog.feedly.com/10-ways-to-optimize-your-feed-for-feedly/
 */
class WebfeedsWriter implements WriterRegistererInterface
{
    public function getRegisteredWriters(): array
    {
        return [
            Webfeeds::class => [$this, 'write'],
        ];
    }

    public function getRegisteredNamespaces(): array
    {
        return [
            'webfeeds' => 'http://webfeeds.org/rss/1.0',
        ];
    }

    public function write(RssWriter $rssWriter, Webfeeds $extension): void
    {
        $writer = $rssWriter->getXmlWriter();

        if ($extension->getLogo()) {
            $writer->writeElement('webfeeds:logo', $extension->getLogo());
        }

        if ($extension->getIcon()) {
            $writer->writeElement('webfeeds:icon', $extension->getIcon());
        }

        if ($extension->getAccentColor()) {
            $writer->writeElement('webfeeds:accentColor', $extension->getAccentColor());
        }
    }
}
