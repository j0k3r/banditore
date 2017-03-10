<?php

namespace AppBundle\Github;

/**
 * Repository to centralize all GraphQL queries for Github.
 */
class GraphQLRepository extends Repository
{
    /**
     * {@inheritdoc}
     */
    public function retrieveStarredRepos($username)
    {
        $query = '{
          user(login: "%USERNAME%") {
            star:starredRepositories(first: 100, orderBy: {field: STARRED_AT, direction: ASC} %AFTER%) {
              pageInfo {
                hasNextPage
                hasPreviousPage
                endCursor
              }
              repos:edges {
                node {
                  id
                  name
                  description
                  owner {
                    login
                    avatarURL
                  }
                }
              }
            }
          }
        }';

        $repos = [];
        $after = '';

        do {
            $res = $this->client->api('graphql')->execute(str_replace(
                ['%USERNAME%', '%AFTER%'],
                [$username, $after],
                $query
            ));

            foreach ($res['data']['user']['star']['repos'] as $repo) {
                // id is base64encoded, like: "MDEwOlJlcG9zaXRvcnk0Mzc4Nw=="
                $idDecoded = base64_decode($repo['node']['id'], true);
                // once decoded it become "010:Repository43787" and we only need to keep the last digit
                $id = split('Repository', $idDecoded)[1];

                $repos[] = [
                    'id' => $id,
                    'name' => $repo['node']['name'],
                    'full_name' => $repo['node']['owner']['login'] . '/' . $repo['node']['name'],
                    'description' => $repo['node']['description'],
                    'owner' => [
                        'avatar_url' => $repo['node']['owner']['avatarURL'],
                    ],
                ];
            }

            // each request might return the cursor for the last element
            // we'll make the next request to start right after that last element using the `endCursor`
            $after = ', after: "' . $res['data']['user']['star']['pageInfo']['endCursor'] . '"';
        } while ($res['data']['user']['star']['pageInfo']['hasNextPage']);

        return $repos;
    }

    /**
     * {@inheritdoc}
     */
    public function retrieveTagsAndReleases($repoFullName)
    {
        list($username, $repoName) = explode('/', $repoFullName);

        $query = '{
          repository(owner: "%OWNER%", name: "%NAME%") {
            tags: refs(refPrefix: "refs/tags/", first: %NB_VERSIONS%, direction: DESC) {
              edges {
                node {
                  name
                  target {
                    ... on Commit {
                      message
                      author {
                        name
                        date
                      }
                    }
                    ... on Tag {
                      message
                      tagger {
                        name
                        date
                      }
                    }
                  }
                }
              }
            }
            releases(last: %NB_VERSIONS%) {
              edges {
                node {
                  id
                  name
                  description
                  publishedAt
                  tag {
                    name
                  }
                }
              }
            }
          }
        }';

        $res = $this->client->api('graphql')->execute(str_replace(
            ['%OWNER%', '%NAME%', '%NB_VERSIONS%'],
            [$username, $repoName, self::NB_VERSIONS],
            $query
        ));

        $versions = [];
        foreach ($res['data']['repository']['tags']['edges'] as $tag) {
            $tag = $tag['node'];

            // author is defined by "tagger" for a tag and "author" for a commit
            $author = isset($tag['target']['tagger']) ? $tag['target']['tagger'] : $tag['target']['author'];

            $versions[$tag['name']] = [
                'name' => $tag['name'],
                'tag_name' => $tag['name'],
                'message' => trim($tag['target']['message']),
                'published_at' => $author['date'],
                // @todo: for the moment, the GraphQL api doesn't allow to retrieve that field
                'prerelease' => false,
            ];
        }

        // no releases for that repo? return all recent tags
        if (empty($res['data']['repository']['releases'])) {
            return $versions;
        }

        foreach ($res['data']['repository']['releases']['edges'] as $release) {
            $release = $release['node'];
            $tagName = $release['tag']['name'];

            $versions[$tagName] = [
                'name' => $release['name'] ?: $tagName,
                'tag_name' => $tagName,
                // override some fields only if the description is filled
                'message' => $release['description'] ?: (isset($versions[$tagName]['message']) ? $versions[$tagName]['message'] : ''),
                // always override these fields, because a release is more important
                'published_at' => $release['publishedAt'],
                // @todo: for the moment, the GraphQL api doesn't allow to retrieve that field
                'prerelease' => false,
            ];
        }

        return $versions;
    }

    public function isGraphQLEnabled()
    {
        $query = '{
          viewer {
            login
          }
        }';

        $res = $this->client->api('graphql')->execute($query);
    }
}
