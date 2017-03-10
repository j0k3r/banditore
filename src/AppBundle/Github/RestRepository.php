<?php

namespace AppBundle\Github;

use AppBundle\Repository\RepoRepository;
use AppBundle\Repository\VersionRepository;
use Github\Client;
use Github\Exception\RuntimeException;

/**
 * Repository to centralize all GraphQL queries for Github.
 */
class RestRepository extends Repository
{
    private $versionRepository;
    private $repoRepository;

    public function setVersionRepository(VersionRepository $versionRepository)
    {
        $this->versionRepository = $versionRepository;
    }

    public function setRepoRepository(RepoRepository $repoRepository)
    {
        $this->repoRepository = $repoRepository;
    }

    /**
     * {@inheritdoc}
     */
    public function retrieveStarredRepos($username)
    {
        $repos = [];
        $page = 1;
        $perPage = 50;
        $starredRepos = $this->client->api('current_user')->starring()->all($page, $perPage);

        do {
            foreach ($starredRepos as $starredRepo) {
                $repos[] = [
                    'id' => $starredRepo['id'],
                    'name' => $starredRepo['name'],
                    'full_name' => $starredRepo['full_name'],
                    'description' => $starredRepo['description'],
                    'owner' => [
                        'avatar_url' => $starredRepo['owner']['avatar_url'],
                    ],
                ];
            }

            $starredRepos = $this->client->api('current_user')->starring()->all($page++, $perPage);
        } while (!empty($starredRepos));

        return $repos;
    }

    /**
     * {@inheritdoc}
     */
    public function retrieveTagsAndReleases($repoFullName)
    {
        $versions = [];
        list($username, $repoName) = explode('/', $repoFullName);

        $repo = $this->repoRepository->findOneBy(['fullName' => $repoFullName]);

        try {
            // retrieve only the last 5 tags (we don't need more)
            $tags = $this->client->api('repo')->tags($username, $repoName, ['per_page' => self::NB_VERSIONS]);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        if (empty($tags)) {
            return $versions;
        }

        foreach ($tags as $tag) {
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
            } catch (RuntimeException $e) {
                // catch this
                //   [Github\Exception\ApiLimitExceedException]
                //   You have reached GitHub hourly limit! Actual limit is: 5000
                $commit = $this->client->api('git')->commits()->show($username, $repoName, $tag['commit']['sha']);

                $newRelease += [
                    'name' => $tag['name'],
                    'prerelease' => false,
                    'published_at' => $commit['author']['date'],
                    'message' => $commit['message'],
                ];
            }

            $versions[] = $newRelease;
        }

        return $versions;
    }
}
