<?php

namespace App\MessageHandler;

use App\Entity\Repo;
use App\Entity\Star;
use App\Entity\User;
use App\Github\RateLimitClient;
use App\Github\RateLimitTrait;
use App\Message\StarredReposSync;
use App\Repository\RepoRepository;
use App\Repository\StarRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\ManagerRegistry;
use Github\Client;
use Github\Exception\RuntimeException;
use Github\ResultPager;
use Predis\ClientInterface as RedisClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Consumer message to sync starred repos from user.
 *
 * It might come from:
 *     - when user logged in
 *     - when we periodically sync user starred repos
 *
 * @phpstan-type SyncMetrics array{user_id: int, user: ?string, status: string, page_requests: int, user_requests: int, stars_seen: int, existing_stars: int, repo_lookups: int, rate_limit_checks: int, flushes: int, added_stars: int, removed_stars: int, timings_ms: array<string, float>}
 */
#[AsMessageHandler]
class StarredReposSyncHandler
{
    use RateLimitTrait;

    public const DAYS_SINCE_LAST_UPDATE = 1;

    /**
     * Client parameter can be null when no available client were found by the Github Client Discovery.
     */
    public function __construct(private ManagerRegistry $doctrine, private UserRepository $userRepository, private StarRepository $starRepository, private RepoRepository $repoRepository, private ?Client $client, private LoggerInterface $logger, private RedisClientInterface $redis, private bool $collectMetrics = false, private ?LoggerInterface $metricsLogger = null, private ?RateLimitClient $rateLimitClient = null)
    {
    }

    public function __invoke(StarredReposSync $message): bool
    {
        $startedAt = hrtime(true);
        $metrics = [
            'user_id' => $message->getUserId(),
            'user' => null,
            'status' => 'failed',
            'page_requests' => 0,
            'user_requests' => 0,
            'stars_seen' => 0,
            'existing_stars' => 0,
            'repo_lookups' => 0,
            'rate_limit_checks' => 0,
            'flushes' => 0,
            'added_stars' => 0,
            'removed_stars' => 0,
            'timings_ms' => array_fill_keys(['user_lookup', 'rate_limit', 'github_pages', 'github_user', 'existing_stars', 'repo_lookup', 'repo_processing', 'flush', 'cleanup'], 0.0),
        ];

        try {
            return $this->sync($message, $metrics);
        } catch (\Throwable $e) {
            $metrics['status'] = 'failed';

            throw $e;
        } finally {
            if ($this->collectMetrics) {
                $this->recordDuration($metrics, 'total', $startedAt);
                $metrics['timings_ms'] = array_map(static fn (float $duration): float => round($duration, 3), $metrics['timings_ms']);
                ($this->metricsLogger ?? $this->logger)->info('Starred repos sync metrics', $metrics);
            }
        }
    }

    /**
     * @param SyncMetrics $metrics
     */
    private function sync(StarredReposSync $message, array &$metrics): bool
    {
        // in case no client with safe RateLimit were found
        if (null === $this->client) {
            $metrics['status'] = 'no_client';
            $this->logger->error('No client provided');

            return false;
        }

        $userId = $message->getUserId();

        $startedAt = hrtime(true);
        try {
            /** @var User|null */
            $user = $this->userRepository->find($userId);
        } finally {
            $this->recordDuration($metrics, 'user_lookup', $startedAt);
        }

        if (null === $user) {
            $metrics['status'] = 'user_not_found';
            $this->logger->error('Can not find user', ['user' => $userId]);

            return false;
        }

        $metrics['user'] = $user->getUsername();

        // to be able to notify user about repos sync (will be remove after 1h to avoid infinite sync notification)
        $this->redis->setex('banditore:user-sync:' . $user->getId(), 3600, time());

        $this->logger->info('Consume banditore.sync_starred_repos message', ['user' => $user->getUsername()]);

        $rateLimit = $this->getMeasuredRateLimits($metrics);

        $this->logger->info('[' . $rateLimit . '] Check <info>' . $user->getUsername() . '</info> … ');

        if (0 === $rateLimit || false === $rateLimit) {
            $metrics['status'] = 'rate_limit_unavailable';
            $this->logger->warning('RateLimit reached, stopping.');

            return false;
        }

        // this shouldn't be catched so the worker will die when an exception is thrown
        $nbRepos = $this->doSyncRepo($user, $metrics);
        if ('partial' !== $metrics['status']) {
            $metrics['status'] = null === $nbRepos ? 'fetch_failed' : 'completed';
        }

        $this->logger->notice('Synced repos: ' . $nbRepos, ['user' => $user->getUsername()]);

        // sync is done, remove notification
        $this->redis->del(['banditore:user-sync:' . $user->getId()]);

        return true;
    }

    /**
     * Do the job to sync repo & star of a user.
     *
     * @param User        $user    User to work on
     * @param SyncMetrics $metrics
     */
    private function doSyncRepo(User $user, array &$metrics): ?int
    {
        $newStars = [];
        $pager = new ResultPager($this->client, 100);

        /** @var EntityManager */
        $em = $this->doctrine->getManager();

        /** @var \Github\Api\User */
        $githubUserApi = $this->client->api('user');

        // in case of the manager is closed following a previous exception
        if (!$em->isOpen()) {
            /** @var EntityManager */
            $em = $this->doctrine->resetManager();
        }

        try {
            $starredRepos = $this->fetchStarredRepos($pager, $githubUserApi, $user, true, $metrics);
        } catch (\Exception $e) {
            $this->logger->warning('(starred) <error>' . $e->getMessage() . '</error>', ['user_id' => $user->getId(), 'user' => $user->getUsername()]);

            if (404 !== $e->getCode() || !$this->refreshUser($githubUserApi, $user, $em, $metrics)) {
                return null;
            }

            try {
                $starredRepos = $this->fetchStarredRepos($pager, $githubUserApi, $user, true, $metrics);
            } catch (\Exception $e) {
                $this->logger->warning('(starred) <error>' . $e->getMessage() . '</error>', ['user_id' => $user->getId(), 'user' => $user->getUsername()]);

                return null;
            }
        }

        $startedAt = hrtime(true);
        try {
            $currentStars = $this->starRepository->findAllByUser($user->getId());
            $metrics['existing_stars'] = \count($currentStars);
        } finally {
            $this->recordDuration($metrics, 'existing_stars', $startedAt);
        }

        do {
            $addedStars = 0;
            $this->logger->info('    sync ' . \count($starredRepos) . ' starred repos', [
                'user' => $user->getUsername(),
            ]);

            foreach ($starredRepos as $starredRepo) {
                ++$metrics['repo_lookups'];
                $startedAt = hrtime(true);
                try {
                    /** @var Repo|null */
                    $repo = $this->repoRepository->find($starredRepo['id']);
                } finally {
                    $this->recordDuration($metrics, 'repo_lookup', $startedAt);
                }

                $startedAt = hrtime(true);
                try {
                    // if repo doesn't exist
                    // OR repo doesn't get updated since XX days
                    if (null === $repo || $repo->getUpdatedAt()->diff(new \DateTime())->days > self::DAYS_SINCE_LAST_UPDATE) {
                        $repo ??= new Repo();

                        $repo->hydrateFromGithub($starredRepo);
                        $em->persist($repo);
                    }

                    // store current repo id to compare it later when we'll sync removed star
                    // using `id` instead of `full_name` to be more accurated (full_name can change)
                    $newStars[] = $repo->getId();

                    if (false === \in_array($repo->getId(), $currentStars, true)) {
                        $star = new Star($user, $repo);

                        $em->persist($star);
                        ++$addedStars;
                    }
                } finally {
                    $this->recordDuration($metrics, 'repo_processing', $startedAt);
                }
            }

            $this->flush($em, $metrics, $addedStars);

            if (!$pager->hasNext()) {
                break;
            }

            try {
                $starredRepos = $this->fetchStarredRepos($pager, $githubUserApi, $user, false, $metrics);
            } catch (RuntimeException) {
                // api limit is reached or whatever other error, we'll try next time
                $metrics['status'] = 'partial';

                return null;
            }
        } while (!empty($starredRepos));

        // now remove unstarred repos
        $startedAt = hrtime(true);
        try {
            $this->doCleanOldStar($user, $newStars, $metrics);
        } finally {
            $this->recordDuration($metrics, 'cleanup', $startedAt);
        }

        return \count($newStars);
    }

    /**
     * Clean old star.
     * When user unstar a repo we also need to remove that association.
     *
     * @param array       $newStars Current starred repos Id of the user
     * @param SyncMetrics $metrics
     */
    private function doCleanOldStar(User $user, array $newStars, array &$metrics): void
    {
        $currentStars = $this->starRepository->findAllByUser($user->getId());
        $repoIdsToRemove = array_diff($currentStars, $newStars);

        if (!empty($repoIdsToRemove)) {
            $this->logger->info('Removed stars: ' . \count($repoIdsToRemove), ['user' => $user->getUsername()]);

            $this->starRepository->removeFromUser($repoIdsToRemove, $user->getId());
            $metrics['removed_stars'] = \count($repoIdsToRemove);
        }
    }

    /**
     * @param SyncMetrics $metrics
     */
    private function fetchStarredRepos(ResultPager $pager, \Github\Api\User $api, User $user, bool $firstPage, array &$metrics): array
    {
        ++$metrics['page_requests'];
        $startedAt = hrtime(true);
        try {
            $repos = $firstPage ? $pager->fetch($api, 'starred', [$user->getUsername(), 1, 100]) : $pager->fetchNext();
            $metrics['stars_seen'] += \count($repos);

            return $repos;
        } finally {
            $this->recordDuration($metrics, 'github_pages', $startedAt);
        }
    }

    /**
     * @param SyncMetrics $metrics
     */
    private function getMeasuredRateLimits(array &$metrics): false|int
    {
        $startedAt = hrtime(true);
        try {
            $remaining = $this->rateLimitClient?->getRecentRemaining();
            if (null !== $remaining) {
                return $remaining;
            }

            ++$metrics['rate_limit_checks'];

            return $this->getRateLimits($this->client, $this->logger);
        } finally {
            $this->recordDuration($metrics, 'rate_limit', $startedAt);
        }
    }

    /**
     * @param SyncMetrics $metrics
     */
    private function refreshUser(\Github\Api\User $api, User $user, EntityManager $em, array &$metrics): bool
    {
        ++$metrics['user_requests'];
        $startedAt = hrtime(true);
        try {
            try {
                $account = $api->showById($user->getId());
            } finally {
                $this->recordDuration($metrics, 'github_user', $startedAt);
            }
        } catch (\Exception $e) {
            $this->logger->warning('(user/id) <error>' . $e->getMessage() . '</error>', ['user_id' => $user->getId(), 'user' => $user->getUsername()]);

            // A stale login is not enough to disable a user; confirm unavailability by durable ID.
            if (404 === $e->getCode()) {
                $user->setRemovedAt(new \DateTime());
                $em->persist($user);
                $this->flush($em, $metrics);
            }

            return false;
        }

        if (($account['id'] ?? null) !== $user->getId() || !\is_string($account['login'] ?? null) || '' === $account['login'] || $account['login'] === $user->getUsername()) {
            return false;
        }

        $user->setUsername($account['login']);
        $em->persist($user);
        $this->flush($em, $metrics);
        $metrics['user'] = $user->getUsername();

        return true;
    }

    /**
     * @param SyncMetrics $metrics
     */
    private function flush(EntityManager $em, array &$metrics, int $addedStars = 0): void
    {
        $startedAt = hrtime(true);
        try {
            $em->flush();
            ++$metrics['flushes'];
            $metrics['added_stars'] += $addedStars;
        } finally {
            $this->recordDuration($metrics, 'flush', $startedAt);
        }
    }

    /**
     * @param SyncMetrics $metrics
     */
    private function recordDuration(array &$metrics, string $phase, int $startedAt): void
    {
        $metrics['timings_ms'][$phase] = ($metrics['timings_ms'][$phase] ?? 0.0) + (hrtime(true) - $startedAt) / 1_000_000;
    }
}
