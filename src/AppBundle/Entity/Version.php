<?php

namespace AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Version
 * Which is an alias of Release (because RELEASE is a reserved keywords).
 *
 * @ORM\Table(
 *     name="version",
 *     options={"collate"="utf8mb4_unicode_ci", "charset"="utf8mb4"},
 *     uniqueConstraints={@ORM\UniqueConstraint(name="repo_version_unique", columns={"repo_id","tag_name"})},
 *     indexes={
 *         @ORM\Index(name="created_at_idx", columns={"created_at"}),
 *         @ORM\Index(name="tag_name_name_created_at_prerelease_repo_id", columns={"tag_name","name","created_at","prerelease","repo_id"})
 *     }
 * )
 * @ORM\Entity(repositoryClass="AppBundle\Repository\VersionRepository")
 */
class Version
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
     * @var string
     *
     * @ORM\Column(name="tag_name", type="string", length=191)
     */
    private $tagName;

    /**
     * @var string
     *
     * @ORM\Column(name="name", type="string", length=191, nullable=true)
     */
    private $name;

    /**
     * @var bool
     *
     * @ORM\Column(name="prerelease", type="boolean")
     */
    private $prerelease;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="created_at", type="datetime")
     */
    private $createdAt;

    /**
     * @var string
     *
     * @ORM\Column(name="body", type="text", nullable=true)
     */
    private $body;

    /**
     * @ORM\ManyToOne(targetEntity="AppBundle\Entity\Repo", inversedBy="versions")
     * @ORM\JoinColumn(name="repo_id", referencedColumnName="id")
     */
    private $repo;

    public function __construct(Repo $repo)
    {
        $this->repo = $repo;
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
     * Set tagName.
     *
     * @param string $tagName
     *
     * @return Version
     */
    public function setTagName($tagName)
    {
        $this->tagName = $tagName;

        return $this;
    }

    /**
     * Get tagName.
     *
     * @return string
     */
    public function getTagName()
    {
        return $this->tagName;
    }

    /**
     * Set name.
     *
     * @param string $name
     *
     * @return Version
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
     * Set prerelease.
     *
     * @param bool $prerelease
     *
     * @return Version
     */
    public function setPrerelease($prerelease)
    {
        $this->prerelease = $prerelease;

        return $this;
    }

    /**
     * Get prerelease.
     *
     * @return bool
     */
    public function getPrerelease()
    {
        return $this->prerelease;
    }

    /**
     * Set createdAt.
     *
     * @param \DateTime $createdAt
     *
     * @return Version
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
     * Set body.
     *
     * @param string $body
     *
     * @return Version
     */
    public function setBody($body)
    {
        $this->body = $body;

        return $this;
    }

    /**
     * Get body.
     *
     * @return string
     */
    public function getBody()
    {
        return $this->body;
    }

    public function hydrateFromGithub(array $data)
    {
        $this->setTagName($data['tag_name']);
        $this->setName($data['name']);
        $this->setPrerelease($data['prerelease']);
        $this->setCreatedAt((new \DateTime())->setTimestamp(strtotime($data['published_at'])));
        $this->setBody($data['message']);
    }
}
