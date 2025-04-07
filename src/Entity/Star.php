<?php

namespace App\Entity;

use App\Repository\StarRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Repo.
 */
#[ORM\Entity(repositoryClass: StarRepository::class)]
#[ORM\Table(name: 'star')]
#[ORM\UniqueConstraint(name: 'user_repo_unique', columns: ['user_id', 'repo_id'])]
class Star
{
    /**
     * @var int
     */
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private $id;

    /**
     * @var \DateTime
     */
    #[ORM\Column(name: 'created_at', type: 'datetime', nullable: false)]
    private $createdAt;

    public function __construct(#[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'stars')]
        #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
        private readonly User $user, #[ORM\ManyToOne(targetEntity: Repo::class, inversedBy: 'stars')]
        #[ORM\JoinColumn(name: 'repo_id', referencedColumnName: 'id', nullable: false)]
        private readonly Repo $repo)
    {
        $this->createdAt = new \DateTime();
    }

    /**
     * Get id.
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set createdAt.
     *
     * @param \DateTime $createdAt
     *
     * @return Star
     */
    public function setCreatedAt($createdAt)
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    /**
     * Get createdAt.
     *
     * @return \DateTime
     */
    public function getCreatedAt()
    {
        return $this->createdAt;
    }
}
