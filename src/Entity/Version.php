<?php

namespace App\Entity;

use App\Repository\VersionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Version
 * Which is an alias of Release (because RELEASE is a reserved keywords).
 */
#[ORM\Entity(repositoryClass: VersionRepository::class)]
#[ORM\Table(name: 'version')]
#[ORM\Index(name: 'created_at_idx', columns: ['created_at'])]
#[ORM\Index(name: 'tag_name_name_created_at_prerelease_repo_id', columns: ['tag_name', 'name', 'created_at', 'prerelease', 'repo_id'])]
#[ORM\UniqueConstraint(name: 'repo_version_unique', columns: ['repo_id', 'tag_name'])]
class Version
{
    /**
     * @var int
     */
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private $id;

    /**
     * @var string
     */
    #[ORM\Column(name: 'tag_name', type: 'string', length: 191)]
    private $tagName;

    /**
     * @var string|null
     */
    #[ORM\Column(name: 'name', type: 'string', length: 191, nullable: true)]
    private $name;

    /**
     * @var bool
     */
    #[ORM\Column(name: 'prerelease', type: 'boolean')]
    private $prerelease;

    /**
     * @var \DateTime
     */
    #[ORM\Column(name: 'created_at', type: 'datetime')]
    private $createdAt;

    /**
     * @var string|null
     */
    #[ORM\Column(name: 'body', type: 'text', nullable: true)]
    private $body;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Repo::class, inversedBy: 'versions')]
        #[ORM\JoinColumn(name: 'repo_id', referencedColumnName: 'id', nullable: false)]
        private readonly Repo $repo,
    ) {
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
        // hard truncate name
        if (mb_strlen($name) > 190) {
            $name = mb_substr($name, 0, 190);
        }

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
        return (string) $this->name;
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
        return (string) $this->body;
    }

    public function hydrateFromGithub(array $data): void
    {
        $this->setTagName($data['tag_name']);
        $this->setName($data['name']);
        $this->setPrerelease($data['prerelease']);
        $this->setCreatedAt((new \DateTime())->setTimestamp(strtotime((string) $data['published_at'])));
        $this->setBody($data['message']);
    }
}
