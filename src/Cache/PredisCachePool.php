<?php

namespace App\Cache;

use Cache\Adapter\Common\AbstractCachePool;
use Cache\Adapter\Common\PhpCacheItem;
use Predis\ClientInterface as Client;

/**
 * Kind of copy/pasted from `cache/predis-adapter` because the project looks dead.
 */
class PredisCachePool extends AbstractCachePool
{
    use HierarchicalCachePoolTrait;

    public function __construct(protected Client $cache)
    {
    }

    protected function fetchObjectFromCache($key)
    {
        $value = $this->cache->get($this->getHierarchyKey($key));
        if (!$value) {
            return [false, null, [], null];
        }

        $result = unserialize($value);
        if (false === $result) {
            return [false, null, [], null];
        }

        return $result;
    }

    protected function clearAllObjectsFromCache()
    {
        return 'OK' === $this->cache->flushdb()->getPayload();
    }

    protected function clearOneObjectFromCache($key)
    {
        $path = null;
        $keyString = $this->getHierarchyKey($key, $path);
        if ($path) {
            $this->cache->incr($path);
        }
        $this->clearHierarchyKeyCache();

        return $this->cache->del($keyString) >= 0;
    }

    protected function storeItemInCache(PhpCacheItem $item, $ttl)
    {
        if ($ttl < 0) {
            return false;
        }

        $key = $this->getHierarchyKey($item->getKey());
        $data = serialize([true, $item->get(), $item->getTags(), $item->getExpirationTimestamp()]);

        if (null === $ttl || 0 === $ttl) {
            return 'OK' === $this->cache->set($key, $data)->getPayload();
        }

        return 'OK' === $this->cache->setex($key, $ttl, $data)->getPayload();
    }

    protected function getDirectValue($key): mixed
    {
        return $this->cache->get($key);
    }

    protected function appendListItem($name, $value): void
    {
        $this->cache->lpush($name, $value);
    }

    protected function getList($name): array
    {
        return $this->cache->lrange($name, 0, -1);
    }

    protected function removeList($name): bool
    {
        return $this->cache->del($name);
    }

    protected function removeListItem($name, $key): int
    {
        return $this->cache->lrem($name, 0, $key);
    }
}
