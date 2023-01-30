<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Repo.
 *
 * @ORM\Table(
 *     name="star",
 *     uniqueConstraints={@ORM\UniqueConstraint(name="user_repo_unique", columns={"user_id","repo_id"})}
 * )
 *
 * @ORM\Entity(repositoryClass="App\Repository\StarRepository")
 */
class Star
{
    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer")
     *
     * @ORM\Id
     *
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\User", inversedBy="stars")
     *
     * @ORM\JoinColumn(name="user_id", referencedColumnName="id", nullable=false)
     */
    private $user;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\Repo", inversedBy="stars")
     *
     * @ORM\JoinColumn(name="repo_id", referencedColumnName="id", nullable=false)
     */
    private $repo;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="created_at", type="datetime", nullable=false)
     */
    private $createdAt;

    public function __construct(User $user, Repo $repo)
    {
        $this->user = $user;
        $this->repo = $repo;
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
