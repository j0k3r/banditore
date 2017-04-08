<?php

namespace AppBundle\Event;

use AppBundle\Entity\Version;
use Symfony\Component\EventDispatcher\Event;

/**
 * Mostly used during RSS import, that event is fired to be able to retrieve more information about
 * the version, like:
 *     - is it a pre-release?
 */
class VersionCreatedEvent extends Event
{
    const NAME = 'version.created';

    /** @var Version */
    protected $version;

    public function __construct(Version $version)
    {
        $this->version = $version;
    }

    public function getVersion()
    {
        return $this->version;
    }
}
