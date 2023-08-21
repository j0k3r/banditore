<?php

namespace App\Repository;

use App\Entity\Star;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class StarRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Star::class);
    }

    /**
     * Retrieve all repos starred by a user.
     *
     * @param int $userId User id
     *
     * @return array
     */
    public function findAllByUser($userId)
    {
        $repos = $this->createQueryBuilder('s')
            ->select('r.id')
            ->leftJoin('s.repo', 'r')
            ->where('s.user = ' . $userId)
            ->getQuery()
            ->getArrayResult();

        $res = [];
        foreach ($repos as $repo) {
            $res[] = $repo['id'];
        }

        return $res;
    }

    /**
     * Remove stars for a user.
     */
    public function removeFromUser(array $repoIds, int $userId): void
    {
        $this->createQueryBuilder('s')
            ->delete()
            ->where('s.repo IN (:ids)')->setParameter('ids', $repoIds)
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
    }

    /**
     * Count total stars.
     *
     * @return int
     */
    public function countTotal()
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id) as total')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
