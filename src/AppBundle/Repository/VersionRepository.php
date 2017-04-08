<?php

namespace AppBundle\Repository;

use AppBundle\Entity\Version;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\AbstractQuery;
use Symfony\Bridge\Doctrine\RegistryInterface;

class VersionRepository extends ServiceEntityRepository
{
    public function __construct(RegistryInterface $registry)
    {
        parent::__construct($registry, Version::class);
    }

    /**
     * Find one version for a given tag name and repo id.
     * This is exactly the same as `findOneBy` but this one use a result cache.
     * Version doesn't change after being inserted and since we check to many times for a version
     * it's faster to store result in a cache.
     *
     * @param string $tagName Tag name to search, like v1.0.0
     * @param int    $repoId  Repository ID
     *
     * @return int|null
     */
    public function findExistingOne($tagName, $repoId)
    {
        $query = $this->createQueryBuilder('v')
            ->select('v.id')
            ->where('v.repo = :repoId')->setParameter('repoId', $repoId)
            ->andWhere('v.tagName = :tagName')->setParameter('tagName', $tagName)
            ->setMaxResults(1)
            ->getQuery()
            ->enableResultCache(3600 * 24 * 31, 'version-' . $repoId . '-' . $tagName)
        ;

        return $query->getOneOrNullResult(AbstractQuery::HYDRATE_SINGLE_SCALAR);
    }

    /**
     * Find all versions available for the given user.
     *
     * @param int $userId
     * @param int $offset
     * @param int $length
     *
     * @return \Generator
     */
    public function findForUser($userId, $offset = 0, $length = 20)
    {
        $releases = $this->createQueryBuilder('v')
            ->select('v.tagName', 'v.name', 'v.createdAt', 'v.body', 'v.prerelease', 'r.fullName', 'r.ownerAvatar', 'r.ownerAvatar', 'r.homepage', 'r.language', 'r.description')
            ->leftJoin('v.repo', 'r')
            ->leftJoin('r.stars', 's')
            ->where('s.user = :userId')->setParameter('userId', $userId)
            ->orderBy('v.createdAt', 'desc')
            ->setFirstResult($offset)
            ->setMaxResults($length)
            ->getQuery()
            ->getArrayResult();

        /*
         * Hacky stuff to convert a badly stored date without a timezone to the correct date
         */
        foreach ($releases as $release) {
            // getTimestamp will retrieve the data and apply the default timezone
            // we then convert the default timezone to UTC
            $utcDate = str_replace(date('P'), 'Z', date('c', $release['createdAt']->getTimestamp()));
            // and finally create a new date with the *REAL* UTC date
            $newDate = (new \DateTime())->setTimestamp(strtotime($utcDate));

            $release['createdAt'] = $newDate;

            yield $release;
        }
    }

    /**
     * Count all versions available for the given user.
     * Used in the dashboard pagination and auth process.
     *
     * @param int $userId
     *
     * @return int
     */
    public function countForUser($userId)
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->leftJoin('v.repo', 'r')
            ->leftJoin('r.stars', 's')
            ->where('s.user = :userId')->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Retrieve latest version of each repo.
     *
     * @param int $length Number of items
     *
     * @return array
     */
    public function findLastVersionForEachRepo($length = 10)
    {
        $query = '
            SELECT v1.tagName, v1.name, v1.createdAt, r.fullName, r.description, r.ownerAvatar, v1.prerelease
            FROM AppBundle\Entity\Version v1
            LEFT JOIN AppBundle\Entity\Version v2 WITH (v1.repo = v2.repo AND v1.createdAt < v2.createdAt)
            LEFT JOIN AppBundle\Entity\Repo r WITH r.id = v1.repo
            WHERE v2.repo IS NULL
            ORDER BY v1.createdAt DESC
        ';

        return $this->getEntityManager()->createQuery($query)
            ->setFirstResult(0)
            ->setMaxResults($length)
            ->getArrayResult();
    }

    /**
     * Count total versions.
     *
     * @return int
     */
    public function countTotal()
    {
        return $this->createQueryBuilder('v')
            ->select('COUNT(v.id) as total')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Retrieve the latest version saved.
     *
     * @return mixed
     */
    public function findLatest()
    {
        return $this->createQueryBuilder('v')
            ->select('v.createdAt')
            ->orderBy('v.createdAt', 'desc')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
