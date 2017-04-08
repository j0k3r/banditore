<?php

namespace AppBundle\Consumer;

use AppBundle\Entity\Repo;
use AppBundle\Entity\Version;
use AppBundle\Event\MaxRssItemsReachedEvent;
use AppBundle\Event\VersionCreatedEvent;
use AppBundle\PubSubHubbub\Publisher;
use AppBundle\Repository\RepoRepository;
use AppBundle\Repository\VersionRepository;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Psr\Log\LoggerInterface;
use SimplePie;
use Swarrot\Broker\Message;
use Swarrot\Processor\ProcessorInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Consumer message to sync user repo using RSS (usually happen after a successful login).
 */
class SyncVersionsRss implements ProcessorInterface
{
    private $doctrine;
    private $repoRepository;
    private $versionRepository;
    private $pubsubhubbub;
    private $logger;
    private $eventDispatcher;
    private $simplePie;

    public function __construct(Registry $doctrine, RepoRepository $repoRepository, VersionRepository $versionRepository, Publisher $pubsubhubbub, EventDispatcherInterface $eventDispatcher, LoggerInterface $logger)
    {
        $this->doctrine = $doctrine;
        $this->repoRepository = $repoRepository;
        $this->versionRepository = $versionRepository;
        $this->pubsubhubbub = $pubsubhubbub;
        $this->logger = $logger;
        $this->eventDispatcher = $eventDispatcher;

        $this->simplePie = new SimplePie();
    }

    /**
     * Set SimplePie.
     * Used to be able to mock SimplePie in tests.
     */
    public function setSimplePie(SimplePie $simplePie)
    {
        $this->simplePie = $simplePie;
    }

    public function process(Message $message, array $options): bool
    {
        $data = json_decode($message->getBody(), true);

        /** @var Repo|null */
        $repo = $this->repoRepository->find($data['repo_id']);

        if (null === $repo) {
            $this->logger->error('Can not find repo', ['repo' => $data['repo_id']]);

            return false;
        }

        $this->logger->info('Consume banditore.sync_versions_rss message', ['repo' => $repo->getFullName()]);

        $this->logger->info('Check <info>' . $repo->getFullName() . '</info> … ');

        // this shouldn't be catched so the worker will die when an exception is thrown
        $nbVersions = $this->doSyncVersions($repo);

        // notify pubsubhubbub for that repo
        if ($nbVersions > 0) {
            $this->pubsubhubbub->pingHub([$data['repo_id']]);
        }

        $this->logger->notice('<comment>' . $nbVersions . '</comment> new versions for <info>' . $repo->getFullName() . '</info>');

        return true;
    }

    /**
     * Do the job to sync repo & star of a user.
     *
     * @param Repo $repo Repo to work on
     */
    private function doSyncVersions(Repo $repo)
    {
        $newVersion = 0;

        /** @var \Doctrine\ORM\EntityManager */
        $em = $this->doctrine->getManager();

        // in case of the manager is closed following a previous exception
        if (!$em->isOpen()) {
            /** @var \Doctrine\ORM\EntityManager */
            $em = $this->doctrine->resetManager();
        }

        try {
            $this->simplePie->force_feed(true);
            $this->simplePie->enable_cache(false);
            $this->simplePie->set_feed_url('https://github.com/' . $repo->getFullName() . '/releases.atom');
            $this->simplePie->init();
        } catch (\Exception $e) {
            $this->logger->warning('(simplePie/init) <error>' . $e->getMessage() . '</error>');

            return;
        }

        $releases = [];
        foreach ($this->simplePie->get_items() as $item) {
            $releases[] = [
                'name' => $item->get_title(),
                'published_at' => (new \DateTime())->setTimestamp(strtotime($item->get_date('r'))),
                'link' => $item->get_link(),
                'message' => trim($item->get_content()),
                'prerelease' => false,
            ];
        }

        if (empty($releases)) {
            return $newVersion;
        }

        foreach ($releases as $release) {
            // it'll be like `/USER/REPO/releases/tag/v1.3`
            $release['tag_name'] = substr($release['link'], stripos($release['link'], '/releases/tag/') + \strlen('/releases/tag/'));

            /** @var Version|null */
            $version = $this->versionRepository->findExistingOne($release['tag_name'], $repo->getId());

            if (null !== $version) {
                continue;
            }

            // check for scheduled version to be persisted later
            // in rare case where the tag name is almost equal, like "v1.1.0" & "V1.1.0" in might avoid error
            foreach ($em->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
                if ($entity instanceof Version && strtolower($entity->getTagName()) === strtolower($release['tag_name'])) {
                    $this->logger->info($release['tag_name'] . ' skipped because it seems to be already scheduled');

                    continue 2;
                }
            }

            $version = new Version($repo);
            $version->hydrateFromGithub($release);

            $em->persist($version);
            $em->flush();

            // in case that version is a "pre-release", use the GitHub API to retrieve that info
            $event = new VersionCreatedEvent($version);
            $this->eventDispatcher->dispatch(VersionCreatedEvent::NAME, $event);

            ++$newVersion;
        }

        // 10 new version might mean we consumed the whole feed and new version might be available
        // send an event to fetch them using the GitHub API
        if (10 === $newVersion) {
            $event = new MaxRssItemsReachedEvent($repo);

            $this->eventDispatcher->dispatch(MaxRssItemsReachedEvent::NAME, $event);
        }

        return $newVersion;
    }
}
