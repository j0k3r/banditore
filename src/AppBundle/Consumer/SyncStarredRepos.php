<?php

namespace AppBundle\Consumer;

use AppBundle\Entity\Repo;
use AppBundle\Entity\Star;
use AppBundle\Entity\User;
use AppBundle\Github\RateLimitTrait;
use AppBundle\Repository\RepoRepository;
use AppBundle\Repository\StarRepository;
use AppBundle\Repository\UserRepository;
use Github\Client;
use Psr\Log\LoggerInterface;
use Swarrot\Broker\Message;
use Swarrot\Processor\ProcessorInterface;
use Symfony\Bridge\Doctrine\RegistryInterface;

/**
 * Consumer message to sync starred repos from user.
 *
 * It might come from:
 *     - when user logged in
 *     - when we periodically sync user starred repos
 */
class SyncStarredRepos implements ProcessorInterface
{
    use RateLimitTrait;

    const DAYS_SINCE_LAST_UPDATE = 1;

    private $logger;
    private $doctrine;
    private $userRepository;
    private $starRepository;
    private $repoRepository;
    private $client;

    /**
     * Client parameter can be null when no available client were found by the Github Client Discovery.
     */
    public function __construct(RegistryInterface $doctrine, UserRepository $userRepository, StarRepository $starRepository, RepoRepository $repoRepository, Client $client = null, LoggerInterface $logger)
    {
        $this->doctrine = $doctrine;
        $this->userRepository = $userRepository;
        $this->starRepository = $starRepository;
        $this->repoRepository = $repoRepository;
        $this->client = $client;
        $this->logger = $logger;
    }

    public function process(Message $message, array $options)
    {
        // in case no client with safe RateLimit were found
        if (null === $this->client) {
            $this->logger->error('No client provided');

            return false;
        }

        $data = json_decode($message->getBody(), true);

        /** @var User|null */
        $user = $this->userRepository->find($data['user_id']);

        if (null === $user) {
            $this->logger->error('Can not find user', ['user' => $data['user_id']]);

            return false;
        }

        $this->logger->info('Consume banditore.sync_starred_repos message', ['user' => $user->getUsername()]);

        $rateLimit = $this->getRateLimits($this->client, $this->logger);

        $this->logger->info('[' . $rateLimit . '] Check <info>' . $user->getUsername() . '</info> … ');

        if (0 === $rateLimit || false === $rateLimit) {
            $this->logger->warning('RateLimit reached, stopping.');

            return false;
        }

        // this shouldn't be catched so the worker will die when an exception is thrown
        $nbRepos = $this->doSyncRepo($user);

        $this->logger->notice('[' . $this->getRateLimits($this->client, $this->logger) . '] Synced repos: ' . $nbRepos, ['user' => $user->getUsername()]);

        return true;
    }

    /**
     * Do the job to sync repo & star of a user.
     *
     * @param User $user User to work on
     */
    private function doSyncRepo(User $user)
    {
        $newStars = [];
        $page = 1;
        $perPage = 100;

        /** @var \Doctrine\ORM\EntityManager */
        $em = $this->doctrine->getManager();

        /** @var \Github\Api\User */
        $githubUserApi = $this->client->api('user');

        $starredRepos = $githubUserApi->starred($user->getUsername(), $page, $perPage);
        $currentStars = $this->starRepository->findAllByUser($user->getId());

        // in case of the manager is closed following a previous exception
        if (!$em->isOpen()) {
            /** @var \Doctrine\ORM\EntityManager */
            $em = $this->doctrine->resetManager();
        }

        do {
            $this->logger->info('    sync ' . \count($starredRepos) . ' starred repos', [
                'user' => $user->getUsername(),
                'rate' => $this->getRateLimits($this->client, $this->logger),
            ]);

            foreach ($starredRepos as $starredRepo) {
                /** @var Repo|null */
                $repo = $this->repoRepository->find($starredRepo['id']);

                // if repo doesn't exist
                // OR repo doesn't get updated since XX days
                if (null === $repo || $repo->getUpdatedAt()->diff(new \DateTime())->days > self::DAYS_SINCE_LAST_UPDATE) {
                    if (null === $repo) {
                        $repo = new Repo();
                    }

                    $repo->hydrateFromGithub($starredRepo);
                    $em->persist($repo);
                }

                // store current repo id to compare it later when we'll sync removed star
                // using `id` instead of `full_name` to be more accurated (full_name can change)
                $newStars[] = $repo->getId();

                if (false === \in_array($repo->getId(), $currentStars, true)) {
                    $star = new Star($user, $repo);

                    $em->persist($star);
                }
            }

            $em->flush();

            $starredRepos = $githubUserApi->starred($user->getUsername(), ++$page, $perPage);
        } while (!empty($starredRepos));

        // now remove unstarred repos
        $this->doCleanOldStar($user, $newStars);

        return \count($newStars);
    }

    /**
     * Clean old star.
     * When user unstar a repo we also need to remove that association.
     *
     * @param array $newStars Current starred repos Id of the user
     *
     * @return mixed
     */
    private function doCleanOldStar(User $user, array $newStars)
    {
        $currentStars = $this->starRepository->findAllByUser($user->getId());

        $repoIdsToRemove = array_diff($currentStars, $newStars);

        if (empty($repoIdsToRemove)) {
            return;
        }

        $this->logger->info('Removed stars: ' . \count($repoIdsToRemove), ['user' => $user->getUsername()]);

        return $this->starRepository->removeFromUser($repoIdsToRemove, $user->getId());
    }
}
