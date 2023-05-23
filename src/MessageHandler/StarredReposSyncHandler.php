<?php

namespace App\MessageHandler;

use App\Entity\Repo;
use App\Entity\Star;
use App\Entity\User;
use App\Github\RateLimitTrait;
use App\Message\StarredReposSync;
use App\Repository\RepoRepository;
use App\Repository\StarRepository;
use App\Repository\UserRepository;
use Doctrine\Persistence\ManagerRegistry;
use Github\Client;
use Github\Exception\RuntimeException;
use Predis\ClientInterface as RedisClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

/**
 * Consumer message to sync starred repos from user.
 *
 * It might come from:
 *     - when user logged in
 *     - when we periodically sync user starred repos
 */
class StarredReposSyncHandler implements MessageHandlerInterface
{
    use RateLimitTrait;

    public const DAYS_SINCE_LAST_UPDATE = 1;

    private $doctrine;
    private $userRepository;
    private $starRepository;
    private $repoRepository;
    private $client;
    private $logger;
    private $redis;

    /**
     * Client parameter can be null when no available client were found by the Github Client Discovery.
     */
    public function __construct(ManagerRegistry $doctrine, UserRepository $userRepository, StarRepository $starRepository, RepoRepository $repoRepository, Client $client = null, LoggerInterface $logger, RedisClientInterface $redis)
    {
        $this->doctrine = $doctrine;
        $this->userRepository = $userRepository;
        $this->starRepository = $starRepository;
        $this->repoRepository = $repoRepository;
        $this->client = $client;
        $this->logger = $logger;
        $this->redis = $redis;
    }

    public function __invoke(StarredReposSync $message): bool
    {
        // in case no client with safe RateLimit were found
        if (null === $this->client) {
            $this->logger->error('No client provided');

            return false;
        }

        $userId = $message->getUserId();

        /** @var User|null */
        $user = $this->userRepository->find($userId);

        if (null === $user) {
            $this->logger->error('Can not find user', ['user' => $userId]);

            return false;
        }

        // to be able to notify user about repos sync (will be remove after 1h to avoid infinite sync notification)
        $this->redis->setex('banditore:user-sync:' . $user->getId(), 3600, time());

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

        // sync is done, remove notification
        $this->redis->del(['banditore:user-sync:' . $user->getId()]);

        return true;
    }

    /**
     * Do the job to sync repo & star of a user.
     *
     * @param User $user User to work on
     */
    private function doSyncRepo(User $user): ?int
    {
        $newStars = [];
        $page = 1;
        $perPage = 100;

        /** @var \Doctrine\ORM\EntityManager */
        $em = $this->doctrine->getManager();

        /** @var \Github\Api\User */
        $githubUserApi = $this->client->api('user');

        // in case of the manager is closed following a previous exception
        if (!$em->isOpen()) {
            /** @var \Doctrine\ORM\EntityManager */
            $em = $this->doctrine->resetManager();
        }

        try {
            $starredRepos = $githubUserApi->starred($user->getUsername(), $page, $perPage);
        } catch (\Exception $e) {
            $this->logger->warning('(starred) <error>' . $e->getMessage() . '</error>');

            // user got removed from GitHub
            if (404 === $e->getCode()) {
                $user->setRemovedAt(new \DateTime());
                $em->persist($user);
            }

            return null;
        }

        $currentStars = $this->starRepository->findAllByUser($user->getId());

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

            try {
                $starredRepos = $githubUserApi->starred($user->getUsername(), ++$page, $perPage);
            } catch (RuntimeException $e) {
                // api limit is reached or whatever other error, we'll try next time
                return null;
            }
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
     */
    private function doCleanOldStar(User $user, array $newStars): void
    {
        $currentStars = $this->starRepository->findAllByUser($user->getId());
        $repoIdsToRemove = array_diff($currentStars, $newStars);

        if (!empty($repoIdsToRemove)) {
            $this->logger->info('Removed stars: ' . \count($repoIdsToRemove), ['user' => $user->getUsername()]);

            $this->starRepository->removeFromUser($repoIdsToRemove, $user->getId());
        }
    }
}
