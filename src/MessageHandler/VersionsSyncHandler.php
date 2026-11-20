<?php

namespace App\MessageHandler;

use App\Entity\Repo;
use App\Entity\Version;
use App\Github\RateLimitTrait;
use App\Message\VersionsSync;
use App\PubSubHubbub\Publisher;
use App\Repository\RepoRepository;
use App\Repository\VersionRepository;
use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\ManagerRegistry;
use Github\Api\GitData;
use Github\Api\Markdown;
use Github\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Consumer message to sync new version from a given repo.
 *
 * @phpstan-type SyncMetrics array{repo_id: int, repo: ?string, status: string, tags: int, existing_version_lookups: int, new_versions: int, timings_ms: array<string, float>}
 */
#[AsMessageHandler]
class VersionsSyncHandler
{
    use RateLimitTrait;

    /**
     * Client parameter can be null when no available client were found by the Github Client Discovery.
     */
    public function __construct(private ManagerRegistry $doctrine, private RepoRepository $repoRepository, private VersionRepository $versionRepository, private Publisher $pubsubhubbub, private ?Client $client, private LoggerInterface $logger, private bool $collectMetrics = false, private ?LoggerInterface $metricsLogger = null)
    {
    }

    public function __invoke(VersionsSync $message): bool
    {
        $startedAt = hrtime(true);
        $metrics = [
            'repo_id' => $message->getRepoId(),
            'repo' => null,
            'status' => 'failed',
            'tags' => 0,
            'existing_version_lookups' => 0,
            'new_versions' => 0,
            'timings_ms' => array_fill_keys(['repo_lookup', 'rate_limit', 'tags', 'existing_versions', 'version_details', 'markdown', 'flush', 'hub'], 0.0),
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
                ($this->metricsLogger ?? $this->logger)->info('Versions sync metrics', $metrics);
            }
        }
    }

    /**
     * @param SyncMetrics $metrics
     */
    private function sync(VersionsSync $message, array &$metrics): bool
    {
        // in case no client with safe RateLimit were found
        if (null === $this->client) {
            $metrics['status'] = 'no_client';
            $this->logger->error('No client provided');

            return false;
        }

        $repoId = $message->getRepoId();

        $startedAt = hrtime(true);
        try {
            /** @var Repo|null */
            $repo = $this->repoRepository->find($repoId);
        } finally {
            $this->recordDuration($metrics, 'repo_lookup', $startedAt);
        }

        if (null === $repo) {
            $metrics['status'] = 'repo_not_found';
            $this->logger->error('Can not find repo', ['repo' => $repoId]);

            return false;
        }

        $metrics['repo'] = $repo->getFullName();
        $this->logger->info('Consume banditore.sync_versions message', ['repo' => $repo->getFullName()]);

        $rateLimit = $this->getMeasuredRateLimits($metrics);

        $this->logger->info('[' . $rateLimit . '] Check <info>' . $repo->getFullName() . '</info> … ');

        if (0 === $rateLimit || false === $rateLimit) {
            $metrics['status'] = 'rate_limit_unavailable';
            $this->logger->warning('RateLimit reached, stopping.');

            return false;
        }

        // this shouldn't be catched so the worker will die when an exception is thrown
        $nbVersions = $this->doSyncVersions($repo, $metrics);
        if ('partial' !== $metrics['status']) {
            $metrics['status'] = null === $nbVersions ? 'fetch_failed' : 'completed';
        }

        // notify pubsubhubbub for that repo
        if ($nbVersions > 0) {
            $startedAt = hrtime(true);
            try {
                $this->pubsubhubbub->pingHub([$repoId]);
            } finally {
                $this->recordDuration($metrics, 'hub', $startedAt);
            }
        }

        $this->logger->notice('<comment>' . $nbVersions . '</comment> new versions for <info>' . $repo->getFullName() . '</info>');

        return true;
    }

    /**
     * Do the job to sync repo & star of a user.
     *
     * @param Repo        $repo    Repo to work on
     * @param SyncMetrics $metrics
     */
    private function doSyncVersions(Repo $repo, array &$metrics): ?int
    {
        $newVersion = 0;

        /** @var EntityManager */
        $em = $this->doctrine->getManager();

        /** @var \Github\Api\Repo */
        $githubRepoApi = $this->client->api('repo');

        // in case of the manager is closed following a previous exception
        if (!$em->isOpen()) {
            /** @var EntityManager */
            $em = $this->doctrine->resetManager();
        }

        [$username, $repoName] = explode('/', $repo->getFullName());

        // Existing versions let us skip the tag probe on the normal path.
        $startedAt = hrtime(true);
        try {
            $hasVersions = $this->versionRepository->hasVersions($repo->getId());
        } finally {
            $this->recordDuration($metrics, 'existing_versions', $startedAt);
        }
        if (!$hasVersions) {
            $startedAt = hrtime(true);
            try {
                $hasTags = $this->hasTags($repo, $githubRepoApi, $em);
            } finally {
                $this->recordDuration($metrics, 'tags', $startedAt);
            }
            if (true !== $hasTags) {
                return false === $hasTags ? 0 : null;
            }
        }

        // use git/refs/tags because tags aren't order by date creation (so we retrieve ALL tags every time …)
        $startedAt = hrtime(true);
        try {
            /** @var GitData */
            $githubGitApi = $this->client->api('git');

            $tags = $githubGitApi->tags()->all($username, $repoName);
        } catch (\Exception $e) {
            // A 404 can mean all tags were deleted, not that the repository disappeared.
            if ($hasVersions && (404 === $e->getCode() || 451 === $e->getCode())) {
                $hasTags = $this->hasTags($repo, $githubRepoApi, $em);
                if (true !== $hasTags) {
                    return false === $hasTags ? 0 : null;
                }
            }

            $this->logger->warning('(git/refs/tags) <error>' . $e->getMessage() . '</error>');

            return null;
        } finally {
            $this->recordDuration($metrics, 'tags', $startedAt);
        }

        $metrics['tags'] = \count($tags);
        foreach ($tags as $tag) {
            // it'll be like `refs/tags/2.2.1`
            $tag['name'] = str_replace('refs/tags/', '', $tag['ref']);
            ++$metrics['existing_version_lookups'];
            $startedAt = hrtime(true);
            try {
                $version = $this->versionRepository->findExistingOne($tag['name'], $repo->getId());
            } finally {
                $this->recordDuration($metrics, 'existing_versions', $startedAt);
            }

            if (null !== $version) {
                continue;
            }

            // check for scheduled version to be persisted later
            // in rare case where the tag name is almost equal, like "v1.1.0" & "V1.1.0" in might avoid error
            foreach ($em->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
                if ($entity instanceof Version && strtolower($entity->getTagName()) === strtolower($tag['name'])) {
                    $this->logger->info($tag['name'] . ' skipped because it seems to be already scheduled');

                    continue 2;
                }
            }

            // is there an associated release?
            $newRelease = [
                'tag_name' => $tag['name'],
            ];

            $startedAt = hrtime(true);
            try {
                $newRelease = $githubRepoApi->releases()->tag($username, $repoName, $tag['name']);

                // use same key as tag to store the content of the release
                $newRelease['message'] = $newRelease['body'];
            } catch (\Exception $e) { // it should be `Github\Exception\RuntimeException` but I can't reproduce this exception in test :-(
                // when a tag isn't a release, it'll be catched here
                switch ($tag['object']['type']) {
                    // https://api.github.com/repos/ampproject/amphtml/git/tags/694b8cc3983f52209029605300910507bec700b4
                    case 'tag':
                        $tagInfo = $githubGitApi->tags()->show($username, $repoName, $tag['object']['sha']);

                        $newRelease += [
                            'name' => $tag['name'],
                            'prerelease' => false,
                            'published_at' => $tagInfo['tagger']['date'],
                            'message' => $tagInfo['message'],
                        ];
                        break;
                        // https://api.github.com/repos/ampproject/amphtml/git/commits/c0a5834b32ae4b45b4bacf677c391e0f9cca82fb
                    case 'commit':
                        $commitInfo = $githubGitApi->commits()->show($username, $repoName, $tag['object']['sha']);

                        $newRelease += [
                            'name' => $tag['name'],
                            'prerelease' => false,
                            'published_at' => $commitInfo['author']['date'],
                            'message' => $commitInfo['message'],
                        ];
                        break;
                    case 'blob':
                        $blobInfo = $githubGitApi->blobs()->show($username, $repoName, $tag['object']['sha']);

                        $newRelease += [
                            'name' => $tag['name'],
                            'prerelease' => false,
                            // we can't retrieve a date for a blob tag, sadly.
                            'published_at' => date('c'),
                            'message' => '(blob, size ' . $blobInfo['size'] . ') ' . base64_decode((string) $blobInfo['content'], true),
                        ];
                        break;
                    default:
                        $metrics['status'] = 'partial';
                        $this->logger->error('<error>Tag object type not supported: ' . $tag['object']['type'] . ' (for: ' . $repo->getFullName() . ')</error>');

                        continue 2;
                }

                $newRelease['message'] = $this->removePgpSignature((string) $newRelease['message']);
            } finally {
                $this->recordDuration($metrics, 'version_details', $startedAt);
            }

            // Render Markdown before saving the version so failed renders can be retried later.
            if (isset($newRelease['message']) && '' !== trim($newRelease['message'])) {
                $startedAt = hrtime(true);
                try {
                    /** @var Markdown */
                    $githubMarkdownApi = $this->client->api('markdown');

                    $newRelease['message'] = $githubMarkdownApi->render($newRelease['message'], 'gfm', $repo->getFullName());
                } catch (\Exception $e) {
                    $metrics['status'] = 'partial';
                    $this->logger->warning('<error>Failed to parse markdown: ' . $e->getMessage() . '</error>');

                    // Stop this repo, but flush versions already rendered before moving on.
                    break;
                } finally {
                    $this->recordDuration($metrics, 'markdown', $startedAt);
                }
            }

            $version = new Version($repo);
            $version->hydrateFromGithub($newRelease);

            $em->persist($version);

            ++$newVersion;

            // for big repos, flush every 200 versions in case of hitting rate limit
            if (0 === ($newVersion % 200)) {
                $this->flushVersions($em, $newVersion, $metrics);
            }
        }

        $this->flushVersions($em, $newVersion, $metrics);

        return $newVersion;
    }

    /**
     * @param SyncMetrics $metrics
     */
    private function getMeasuredRateLimits(array &$metrics): false|int
    {
        $startedAt = hrtime(true);
        try {
            return $this->getRateLimits($this->client, $this->logger);
        } finally {
            $this->recordDuration($metrics, 'rate_limit', $startedAt);
        }
    }

    /**
     * The tag-list endpoint returns a cacheable empty array when there are no tags,
     * unlike git/refs/tags, whose 404 is ambiguous.
     */
    private function hasTags(Repo $repo, \Github\Api\Repo $githubRepoApi, EntityManager $em): ?bool
    {
        [$username, $repoName] = explode('/', $repo->getFullName());

        try {
            $tags = $githubRepoApi->tags($username, $repoName, ['per_page' => 1, 'page' => 1]);

            return !empty($tags);
        } catch (\Exception $e) {
            $this->logger->warning('(repo/tags) <error>' . $e->getMessage() . '</error>');

            if (404 === $e->getCode() || 451 === $e->getCode()) {
                $repo->setRemovedAt(new \DateTime());
                $em->persist($repo);
                $em->flush();
            }

            return null;
        }
    }

    /**
     * @param SyncMetrics $metrics
     */
    private function flushVersions(EntityManager $em, int $newVersions, array &$metrics): void
    {
        $startedAt = hrtime(true);
        try {
            $em->flush();
            $metrics['new_versions'] = $newVersions;
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

    /**
     * Remove PGP signature from commit / tag.
     */
    private function removePgpSignature(string $message): string
    {
        $pos = stripos($message, '-----BEGIN PGP SIGNATURE-----');
        if ($pos) {
            return trim(substr($message, 0, $pos));
        }

        return $message;
    }
}
