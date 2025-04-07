<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method User|null findOneByUsername(string $username)
 *
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Retrieve user.
     *
     * @return array
     */
    public function findByRepoIds(array $repoIds)
    {
        return $this->createQueryBuilder('u')
            ->select('DISTINCT u.uuid')
            ->leftJoin('u.stars', 's')
            ->where('s.repo IN (:ids)')->setParameter('ids', $repoIds)
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Retrieve all users to be synced.
     * We only retrieve ids to be as fast as possible.
     *
     * @return array
     */
    public function findAllToSync()
    {
        $data = $this->createQueryBuilder('u')
            ->select('u.id')
            ->where('u.removedAt IS NULL')
            ->getQuery()
            ->getArrayResult();

        $return = [];
        foreach ($data as $oneData) {
            $return[] = $oneData['id'];
        }

        return $return;
    }

    /**
     * Retrieve all tokens available.
     * This is used for the GithubClientDiscovery.
     *
     * @return array
     */
    public function findAllTokens()
    {
        return $this->createQueryBuilder('u')
            ->select('u.id', 'u.username', 'u.accessToken')
            ->where('u.removedAt IS NULL')
            ->getQuery()
            ->enableResultCache()
            ->setResultCacheLifetime(10 * 60)
            ->getArrayResult();
    }

    /**
     * Count total users.
     *
     * @return int
     */
    public function countTotal()
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id) as total')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
