<?php

namespace AppBundle\Consumer;

use AppBundle\Entity\Repo;
use AppBundle\Entity\Version;
use AppBundle\PubSubHubbub\Publisher;
use AppBundle\Repository\RepoRepository;
use AppBundle\Repository\VersionRepository;
use Doctrine\ORM\EntityManager;
use Github\Client;
use Psr\Log\LoggerInterface;
use Swarrot\Broker\Message;
use Swarrot\Processor\ProcessorInterface;

/**
 * Consumer message to sync user repo (usually happen after a successful login).
 */
class SyncVersions implements ProcessorInterface
{
    private $em;
    private $repoRepository;
    private $versionRepository;
    private $pubsubhubbub;
    private $logger;
    private $client;

    public function __construct(EntityManager $em, RepoRepository $repoRepository, VersionRepository $versionRepository, Publisher $pubsubhubbub, Client $client, LoggerInterface $logger)
    {
        $this->em = $em;
        $this->repoRepository = $repoRepository;
        $this->versionRepository = $versionRepository;
        $this->pubsubhubbub = $pubsubhubbub;
        $this->client = $client;
        $this->logger = $logger;
    }

    public function process(Message $message, array $options)
    {
        $data = json_decode($message->getBody(), true);

        $repo = $this->repoRepository->find($data['repo_id']);

        if (null === $repo) {
            $this->logger->error('Can not find repo', ['repo' => $data['repo_id']]);

            return;
        }

        $this->logger->notice('Consume banditore.sync_versions message', ['repo' => $repo->getFullName()]);

        $rateLimit = $this->client->api('rate_limit')->getRateLimits();
        $this->logger->notice('[' . $rateLimit['resources']['core']['remaining'] . '] Check <info>' . $repo->getFullName() . '</info> … ');

        try {
            $nbVersions = $this->doSyncVersions($repo);
        } catch (\Exception $e) {
            $this->logger->error('Error while syncing repo', ['exception' => get_class($e), 'message' => $e->getMessage(), 'repo' => $repo->getFullName()]);

            return;
        }

        // notify pubsubhubbub for that repo
        if ($nbVersions > 0) {
            $this->pubsubhubbub->pingHub([$data['repo_id']]);
        }

        $rateLimit = $this->client->api('rate_limit')->getRateLimits();
        $this->logger->notice('[' . $rateLimit['resources']['core']['remaining'] . '] <comment>' . $nbVersions . '</comment> new versions for <info>' . $repo->getFullName() . '</info>');
    }

    /**
     * Do the job to sync repo & star of a user.
     *
     * @param Repo $repo Repo to work on
     */
    private function doSyncVersions(Repo $repo)
    {
        $newVersion = 0;

        list($username, $repoName) = explode('/', $repo->getFullName());

        // this is a simple call to retrieve at least one tag from the selected repo
        // using git/refs/tags when repo has no tag throws a 404 which can't be cached
        // this query return an empty array when repo has no tag and it can be cached
        try {
            $tags = $this->client->api('repo')->tags($username, $repoName, ['per_page' => 1, 'page' => 1]);
        } catch (\Exception $e) {
            $this->logger->warning('(repo/tags) <error>' . $e->getMessage() . '</error>');

            return;
        }

        if (empty($tags)) {
            return $newVersion;
        }

        // use git/refs/tags because tags aren't order by date creation (so we retrieve ALL tags every time …)
        try {
            $tags = $this->client->api('git')->tags()->all($username, $repoName);
        } catch (\Exception $e) {
            $this->logger->warning('(git/refs/tags) <error>' . $e->getMessage() . '</error>');

            return;
        }

        foreach ($tags as $tag) {
            // it'll be like `refs/tags/2.2.1`
            $tag['name'] = str_replace('refs/tags/', '', $tag['ref']);
            $version = $this->versionRepository->findOneBy([
                'tagName' => $tag['name'],
                'repo' => $repo->getId(),
            ]);

            if (null !== $version) {
                continue;
            }

            // is there an associated release?
            $newRelease = [
                'tag_name' => $tag['name'],
            ];

            try {
                $newRelease = $this->client->api('repo')->releases()->tag($username, $repoName, $tag['name']);

                // use same key as tag to store the content of the release
                $newRelease['message'] = $newRelease['body'];
            } catch (\Exception $e) { // it should be `Github\Exception\RuntimeException` but I can't reproduce this exception in test :-(
                // when a tag isn't a release, it'll be catched here
                switch ($tag['object']['type']) {
                    // https://api.github.com/repos/ampproject/amphtml/git/tags/694b8cc3983f52209029605300910507bec700b4
                    case 'tag':
                        $tagInfo = $this->client->api('git')->tags()->show($username, $repoName, $tag['object']['sha']);

                        $newRelease += [
                            'name' => $tag['name'],
                            'prerelease' => false,
                            'published_at' => $tagInfo['tagger']['date'],
                            'message' => $tagInfo['message'],
                        ];
                        break;
                    // https://api.github.com/repos/ampproject/amphtml/git/commits/c0a5834b32ae4b45b4bacf677c391e0f9cca82fb
                    case 'commit':
                        $commitInfo = $this->client->api('git')->commits()->show($username, $repoName, $tag['object']['sha']);

                        $newRelease += [
                            'name' => $tag['name'],
                            'prerelease' => false,
                            'published_at' => $commitInfo['author']['date'],
                            'message' => $commitInfo['message'],
                        ];
                }

                $newRelease['message'] = $this->removePgpSignature($newRelease['message']);
            }

            // render markdown in plain html and use default markdown file if it fails
            if (isset($newRelease['message']) && strlen(trim($newRelease['message'])) > 0) {
                try {
                    $newRelease['message'] = $this->client->api('markdown')->render($newRelease['message'], 'gfm', $repo->getFullName());
                } catch (\Exception $e) {
                    $this->logger->warning('<error>Failed to parse markdown: ' . $e->getMessage() . '</error>');
                    continue;
                }
            }

            $version = new Version($repo);
            $version->hydrateFromGithub($newRelease);

            $this->em->persist($version);

            ++$newVersion;
        }

        $this->em->flush();

        return $newVersion;
    }

    /**
     * Remove PGP signature from commit / tag.
     *
     * @param string $message
     *
     * @return string
     */
    private function removePgpSignature($message)
    {
        if ($pos = stripos($message, '-----BEGIN PGP SIGNATURE-----')) {
            return trim(substr($message, 0, $pos));
        }

        return $message;
    }
}
