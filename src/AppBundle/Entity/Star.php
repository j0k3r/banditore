<?php

namespace AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Repo.
 *
 * @ORM\Table(
 *     name="star",
 *     options={"collate"="utf8mb4_unicode_ci", "charset"="utf8mb4"},
 *     uniqueConstraints={@ORM\UniqueConstraint(name="user_repo_unique", columns={"user_id","repo_id"})}
 * )
 * @ORM\Entity(repositoryClass="AppBundle\Repository\StarRepository")
 */
class Star
{
    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity="AppBundle\Entity\User", inversedBy="stars")
     * @ORM\JoinColumn(name="user_id", referencedColumnName="id")
     */
    private $user;

    /**
     * @ORM\ManyToOne(targetEntity="AppBundle\Entity\Repo", inversedBy="stars")
     * @ORM\JoinColumn(name="repo_id", referencedColumnName="id")
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
     * @return User
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
