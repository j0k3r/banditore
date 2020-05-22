<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Repo.
 *
 * @ORM\Table(name="repo")
 * @ORM\Entity(repositoryClass="App\Repository\RepoRepository")
 * @ORM\HasLifecycleCallbacks()
 */
class Repo
{
    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="NONE")
     */
    private $id;

    /**
     * @var string
     *
     * @ORM\Column(name="name", type="string", length=191)
     */
    private $name;

    /**
     * @var string
     *
     * @ORM\Column(name="full_name", type="string", length=191)
     */
    private $fullName;

    /**
     * @var string
     *
     * @ORM\Column(name="description", type="text", nullable=true)
     */
    private $description;

    /**
     * @var string
     *
     * @ORM\Column(name="homepage", type="string", nullable=true)
     */
    private $homepage;

    /**
     * @var string
     *
     * @ORM\Column(name="language", type="string", nullable=true)
     */
    private $language;

    /**
     * @var string
     *
     * @ORM\Column(name="owner_avatar", type="string", length=191)
     */
    private $ownerAvatar;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="created_at", type="datetime", nullable=false)
     */
    private $createdAt;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="updated_at", type="datetime", nullable=false)
     */
    private $updatedAt;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="removed_at", type="datetime", nullable=true)
     */
    private $removedAt;

    /**
     * @ORM\OneToMany(targetEntity="Star", mappedBy="repo")
     */
    private $stars;

    /**
     * @ORM\OneToMany(targetEntity="Version", mappedBy="repo")
     */
    private $versions;

    public function __construct()
    {
        $this->stars = new ArrayCollection();
        $this->versions = new ArrayCollection();
    }

    /**
     * Set id.
     *
     * @param int $id
     *
     * @return Repo
     */
    public function setId($id)
    {
        $this->id = $id;

        return $this;
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
     * Set name.
     *
     * @param string $name
     *
     * @return Repo
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get name.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set fullName.
     *
     * @param string $fullName
     *
     * @return Repo
     */
    public function setFullName($fullName)
    {
        $this->fullName = $fullName;

        return $this;
    }

    /**
     * Get fullName.
     *
     * @return string
     */
    public function getFullName()
    {
        return $this->fullName;
    }

    /**
     * Set description.
     *
     * @param string $description
     *
     * @return Repo
     */
    public function setDescription($description)
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Get description.
     *
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Set homepage.
     *
     * @param string $homepage
     *
     * @return Repo
     */
    public function setHomepage($homepage)
    {
        $this->homepage = $homepage;

        return $this;
    }

    /**
     * Get homepage.
     *
     * @return string
     */
    public function getHomepage()
    {
        return $this->homepage;
    }

    /**
     * Set language.
     *
     * @param string $language
     *
     * @return Repo
     */
    public function setLanguage($language)
    {
        $this->language = $language;

        return $this;
    }

    /**
     * Get language.
     *
     * @return string
     */
    public function getLanguage()
    {
        return $this->language;
    }

    /**
     * Set ownerAvatar.
     *
     * @param string $ownerAvatar
     *
     * @return Repo
     */
    public function setOwnerAvatar($ownerAvatar)
    {
        $this->ownerAvatar = $ownerAvatar;

        return $this;
    }

    /**
     * Get ownerAvatar.
     *
     * @return string
     */
    public function getOwnerAvatar()
    {
        return $this->ownerAvatar;
    }

    /**
     * Set createdAt.
     *
     * @param \DateTime $createdAt
     *
     * @return Repo
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

    /**
     * Set updatedAt.
     *
     * @param \DateTime $updatedAt
     *
     * @return Repo
     */
    public function setUpdatedAt($updatedAt)
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    /**
     * Get updatedAt.
     *
     * @return \DateTime
     */
    public function getUpdatedAt()
    {
        return $this->updatedAt;
    }

    /**
     * @ORM\PrePersist
     * @ORM\PreUpdate
     */
    public function timestamps(): void
    {
        if (null === $this->createdAt) {
            $this->createdAt = new \DateTime();
        }
        $this->updatedAt = new \DateTime();
    }

    public function hydrateFromGithub(array $data): void
    {
        $this->setId($data['id']);
        $this->setName($data['name']);
        $this->setHomepage($data['homepage']);
        $this->setLanguage($data['language']);
        $this->setFullName($data['full_name']);
        $this->setDescription($data['description']);
        $this->setOwnerAvatar($data['owner']['avatar_url']);
    }

    /**
     * Set removedAt.
     *
     * @param \DateTime $removedAt
     *
     * @return Repo
     */
    public function setRemovedAt($removedAt)
    {
        $this->removedAt = $removedAt;

        return $this;
    }

    /**
     * Get removedAt.
     *
     * @return \DateTime
     */
    public function getRemovedAt()
    {
        return $this->removedAt;
    }
}
