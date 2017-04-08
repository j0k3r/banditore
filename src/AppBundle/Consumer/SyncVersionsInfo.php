<?php

namespace AppBundle\Consumer;

use AppBundle\Entity\Version;
use AppBundle\Github\RateLimitTrait;
use AppBundle\Repository\VersionRepository;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Github\Client;
use Psr\Log\LoggerInterface;
use Swarrot\Broker\Message;
use Swarrot\Processor\ProcessorInterface;

/**
 * Consumer message to sync info for a given version mostly because it has been created using the RSS feed.
 *
 * For now it's used to retrieve:
 *     - prerelease
 */
class SyncVersionsInfo implements ProcessorInterface
{
    use RateLimitTrait;

    private $doctrine;
    private $versionRepository;
    private $client;
    private $logger;

    /**
     * Client parameter can be null when no available client were found by the Github Client Discovery.
     */
    public function __construct(Registry $doctrine, VersionRepository $versionRepository, Client $client = null, LoggerInterface $logger)
    {
        $this->doctrine = $doctrine;
        $this->versionRepository = $versionRepository;
        $this->client = $client;
        $this->logger = $logger;
    }

    public function process(Message $message, array $options): bool
    {
        // in case no client with safe RateLimit were found
        if (null === $this->client) {
            $this->logger->error('No client provided');

            return false;
        }

        $data = json_decode($message->getBody(), true);

        /** @var Version|null */
        $version = $this->versionRepository->find($data['version_id']);

        if (null === $version) {
            $this->logger->error('Can not find version', ['version' => $data['version_id']]);

            return false;
        }

        $this->logger->info('Consume banditore.sync_versions_info message', ['version' => $version->getName()]);

        $rateLimit = $this->getRateLimits($this->client, $this->logger);

        $this->logger->info('[' . $rateLimit . '] Check <info>' . $version->getName() . ' (' . $version->getId() . ')</info> … ');

        $this->doSyncInfo($version);

        $this->logger->notice('[' . $this->getRateLimits($this->client, $this->logger) . '] Info fetched for <info>' . $version->getName() . ' (repo id: ' . $version->getRepo()->getId() . ')</info>');

        return true;
    }

    /**
     * Do the job to retrieve information from that version.
     *
     * @param Version $version Version to work on
     */
    private function doSyncInfo(Version $version)
    {
        /** @var \Doctrine\ORM\EntityManager */
        $em = $this->doctrine->getManager();

        // in case of the manager is closed following a previous exception
        if (!$em->isOpen()) {
            /** @var \Doctrine\ORM\EntityManager */
            $em = $this->doctrine->resetManager();
        }

        /** @var \Github\Api\Repo */
        $githubRepoApi = $this->client->api('repo');

        list($username, $repoName) = explode('/', $version->getRepo()->getFullName());

        // try to get info from that version only if it's a release otherwise we won't need more information
        try {
            $release = $githubRepoApi->releases()->tag($username, $repoName, $version->getTagName());
        } catch (\Exception $e) {
            // it's not a release, so we don't care
            return false;
        }

        $version->setPrerelease($release['prerelease']);

        $em->persist($version);
        $em->flush();

        return true;
    }
}
