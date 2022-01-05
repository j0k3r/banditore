<?php

namespace App\MessageHandler;

use App\Entity\Repo;
use App\Entity\Version;
use App\Github\RateLimitTrait;
use App\Message\VersionsSync;
use App\PubSubHubbub\Publisher;
use App\Repository\RepoRepository;
use App\Repository\VersionRepository;
use Doctrine\Persistence\ManagerRegistry;
use Github\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

/**
 * Consumer message to sync new version from a given repo.
 */
class VersionsSyncHandler implements MessageHandlerInterface
{
    use RateLimitTrait;

    private $doctrine;
    private $repoRepository;
    private $versionRepository;
    private $pubsubhubbub;
    private $logger;
    private $client;

    /**
     * Client parameter can be null when no available client were found by the Github Client Discovery.
     */
    public function __construct(ManagerRegistry $doctrine, RepoRepository $repoRepository, VersionRepository $versionRepository, Publisher $pubsubhubbub, Client $client = null, LoggerInterface $logger)
    {
        $this->doctrine = $doctrine;
        $this->repoRepository = $repoRepository;
        $this->versionRepository = $versionRepository;
        $this->pubsubhubbub = $pubsubhubbub;
        $this->client = $client;
        $this->logger = $logger;
    }

    public function __invoke(VersionsSync $message): bool
    {
        // in case no client with safe RateLimit were found
        if (null === $this->client) {
            $this->logger->error('No client provided');

            return false;
        }

        $repoId = $message->getRepoId();

        /** @var Repo|null */
        $repo = $this->repoRepository->find($repoId);

        if (null === $repo) {
            $this->logger->error('Can not find repo', ['repo' => $repoId]);

            return false;
        }

        $this->logger->info('Consume banditore.sync_versions message', ['repo' => $repo->getFullName()]);

        $rateLimit = $this->getRateLimits($this->client, $this->logger);

        $this->logger->info('[' . $rateLimit . '] Check <info>' . $repo->getFullName() . '</info> … ');

        if (0 === $rateLimit || false === $rateLimit) {
            $this->logger->warning('RateLimit reached, stopping.');

            return false;
        }

        // this shouldn't be catched so the worker will die when an exception is thrown
        $nbVersions = $this->doSyncVersions($repo);

        // notify pubsubhubbub for that repo
        if ($nbVersions > 0) {
            $this->pubsubhubbub->pingHub([$repoId]);
        }

        $this->logger->notice('[' . $this->getRateLimits($this->client, $this->logger) . '] <comment>' . $nbVersions . '</comment> new versions for <info>' . $repo->getFullName() . '</info>');

        return true;
    }

    /**
     * Do the job to sync repo & star of a user.
     *
     * @param Repo $repo Repo to work on
     */
    private function doSyncVersions(Repo $repo): ?int
    {
        $newVersion = 0;

        /** @var \Doctrine\ORM\EntityManager */
        $em = $this->doctrine->getManager();

        /** @var \Github\Api\Repo */
        $githubRepoApi = $this->client->api('repo');

        // in case of the manager is closed following a previous exception
        if (!$em->isOpen()) {
            /** @var \Doctrine\ORM\EntityManager */
            $em = $this->doctrine->resetManager();
        }

        list($username, $repoName) = explode('/', $repo->getFullName());

        // this is a simple call to retrieve at least one tag from the selected repo
        // using git/refs/tags when repo has no tag throws a 404 which can't be cached
        // this query return an empty array when repo has no tag and it can be cached
        try {
            $tags = $githubRepoApi->tags($username, $repoName, ['per_page' => 1, 'page' => 1]);
        } catch (\Exception $e) {
            $this->logger->warning('(repo/tags) <error>' . $e->getMessage() . '</error>');

            // repo not found OR access blocked? Ignore it in future loops
            if (404 === $e->getCode() || 451 === $e->getCode()) {
                $repo->setRemovedAt(new \DateTime());
                $em->persist($repo);
            }

            return null;
        }

        if (empty($tags)) {
            return $newVersion;
        }

        // use git/refs/tags because tags aren't order by date creation (so we retrieve ALL tags every time …)
        try {
            /** @var \Github\Api\GitData */
            $githubGitApi = $this->client->api('git');

            $tags = $githubGitApi->tags()->all($username, $repoName);
        } catch (\Exception $e) {
            $this->logger->warning('(git/refs/tags) <error>' . $e->getMessage() . '</error>');

            return null;
        }

        foreach ($tags as $tag) {
            // it'll be like `refs/tags/2.2.1`
            $tag['name'] = str_replace('refs/tags/', '', $tag['ref']);
            $version = $this->versionRepository->findExistingOne($tag['name'], $repo->getId());

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
                            'message' => '(blob, size ' . $blobInfo['size'] . ') ' . base64_decode($blobInfo['content'], true),
                        ];
                        break;
                    default:
                        $this->logger->error('<error>Tag object type not supported: ' . $tag['object']['type'] . ' (for: ' . $repo->getFullName() . ')</error>');

                        continue 2;
                }

                $newRelease['message'] = $this->removePgpSignature((string) $newRelease['message']);
            }

            // render markdown in plain html and use default markdown file if it fails
            if (isset($newRelease['message']) && '' !== trim($newRelease['message'])) {
                try {
                    /** @var \Github\Api\Markdown */
                    $githubMarkdownApi = $this->client->api('markdown');

                    $newRelease['message'] = $githubMarkdownApi->render($newRelease['message'], 'gfm', $repo->getFullName());
                } catch (\Exception $e) {
                    $this->logger->warning('<error>Failed to parse markdown: ' . $e->getMessage() . '</error>');

                    // it is usually a problem from the abuse detection mechanism, to avoid multiple call, we just skip to the next repo
                    return $newVersion;
                }
            }

            $version = new Version($repo);
            $version->hydrateFromGithub($newRelease);

            $em->persist($version);

            ++$newVersion;

            // for big repos, flush every 200 versions in case of hitting rate limit
            if (0 === ($newVersion % 200)) {
                $em->flush();
            }
        }

        $em->flush();

        return $newVersion;
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
