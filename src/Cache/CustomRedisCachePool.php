<?php

namespace App\Cache;

use Cache\Adapter\Common\PhpCacheItem;

/**
 * Store lightweight response from GitHub to avoid having a huge Redis database.
 * Stored response will only have what Bandito.re needs. We should use GraphQL to only request fields we want
 * but rate limit is still too low for the app.
 *
 * Affected url from the GitHub API:
 *     - starred
 *     - git/refs/tags
 *     - tag
 *     - release
 *
 * All other response are usually for a version and we don't need to store them. They won't be cached.
 */
class CustomRedisCachePool extends PredisCachePool
{
    protected function storeItemInCache(PhpCacheItem $item, $ttl): bool
    {
        if ($ttl < 0) {
            return false;
        }

        $currentItem = $item->get();

        if (404 === $currentItem['response']->getStatusCode() || 451 === $currentItem['response']->getStatusCode()) {
            return parent::storeItemInCache($item, $ttl);
        }

        $body = json_decode((string) $currentItem['body'], true);
        // we don't need to reduce empty array ^^
        if (empty($body)) {
            return parent::storeItemInCache($item, $ttl);
        }

        // do not cache version (ie: release or tag) information
        // we don't query them later because the version will be saved and never updated
        if (isset($body['committer']) || isset($body['tagger']) || isset($body['prerelease'])) {
            return true;
        }

        if (isset($body[0]['ref']) && str_contains((string) $body[0]['ref'], 'refs/tags/')) {
            // response for git/refs/tags
            foreach ($body as $key => $element) {
                $body[$key] = [
                    'ref' => $element['ref'],
                    'object' => [
                        'sha' => $element['object']['sha'],
                        'type' => $element['object']['type'],
                    ],
                ];
            }
        } elseif (isset($body[0]['zipball_url'])) {
            // response for only one tag
            $body = [
                0 => [
                    'name' => $body[0]['name'],
                ],
            ];
        } elseif (isset($body[0]['full_name'])) {
            // response for starred repos
            foreach ($body as $key => $element) {
                $body[$key] = [
                    'id' => $element['id'],
                    'name' => $element['name'],
                    'homepage' => $element['homepage'],
                    'language' => $element['language'],
                    'full_name' => $element['full_name'],
                    'description' => $element['description'],
                    'owner' => [
                        'avatar_url' => $element['owner']['avatar_url'],
                    ],
                ];
            }
        } else {
            $this->log('warning', 'Unmatched response from custom Redis cache', ['body' => $body]);
        }

        $currentItem['body'] = json_encode($body);

        $item->set($currentItem);

        return parent::storeItemInCache($item, $ttl);
    }
}
