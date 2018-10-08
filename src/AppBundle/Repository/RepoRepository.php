<?php

namespace AppBundle\Repository;

use AppBundle\Entity\Repo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Symfony\Bridge\Doctrine\RegistryInterface;

class RepoRepository extends ServiceEntityRepository
{
    public function __construct(RegistryInterface $registry)
    {
        parent::__construct($registry, Repo::class);
    }

    /**
     * Retrieve all repositories to be fetched for new release.
     *
     * @return array
     */
    public function findAllForRelease()
    {
        $data = $this->createQueryBuilder('r')
            ->select('r.id')
            ->where('r.removedAt IS NULL')
            ->getQuery()
            ->getArrayResult();

        $return = [];
        foreach ($data as $oneData) {
            $return[] = $oneData['id'];
        }

        return $return;
    }

    /**
     * Count total repos.
     *
     * @return int
     */
    public function countTotal()
    {
        return $this->createQueryBuilder('r')
            ->select('COUNT(r.id) as total')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Retrieve repos with the most releases.
     * Used for stats.
     *
     * @return array
     */
    public function mostVersionsPerRepo()
    {
        return $this->createQueryBuilder('r')
            ->select('r.fullName', 'r.description', 'r.ownerAvatar')
            ->addSelect('(SELECT COUNT(v.id)
                FROM AppBundle\Entity\Version v
                WHERE v.repo = r.id) AS total'
            )
            ->groupBy('r.fullName', 'r.description', 'r.ownerAvatar', 'total')
            ->orderBy('total', 'desc')
            ->setMaxResults(5)
            ->getQuery()
            ->getArrayResult();
    }
}
