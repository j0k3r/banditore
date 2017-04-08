<?php

namespace AppBundle\Event;

use AppBundle\Entity\Repo;
use Symfony\Component\EventDispatcher\Event;

/**
 * RSS feed only contains 10 element. If we reach 10 element when fetching there should be more
 * element to fetch, use the GitHub API for that.
 */
class MaxRssItemsReachedEvent extends Event
{
    const NAME = 'repo.max_rss_items_reached';

    /** @var Repo */
    protected $repo;

    public function __construct(Repo $repo)
    {
        $this->repo = $repo;
    }

    public function getRepo()
    {
        return $this->repo;
    }
}
