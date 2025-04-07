<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use League\OAuth2\Client\Provider\GithubResourceOwner;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * User.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'user')]
#[ORM\UniqueConstraint(name: 'uuid', columns: ['uuid'])]
class User implements UserInterface, EquatableInterface
{
    /**
     * @var int
     */
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private $id;

    /**
     * @var string
     */
    #[ORM\Column(name: 'uuid', type: 'guid', length: 191, unique: true, nullable: false)]
    private $uuid;

    /**
     * @var string
     */
    #[ORM\Column(name: 'username', type: 'string', length: 191, unique: true, nullable: false)]
    private $username;

    /**
     * @var string
     */
    #[ORM\Column(name: 'avatar', type: 'string', length: 191)]
    private $avatar;

    /**
     * @var string|null
     */
    #[ORM\Column(name: 'name', type: 'string', length: 191, nullable: true)]
    private $name;

    /**
     * @var string
     */
    #[ORM\Column(name: 'access_token', type: 'string', length: 100, nullable: false)]
    private $accessToken;

    /**
     * @var \DateTime
     */
    #[ORM\Column(name: 'created_at', type: 'datetime', nullable: false)]
    private $createdAt;

    /**
     * @var \DateTime
     */
    #[ORM\Column(name: 'updated_at', type: 'datetime', nullable: false)]
    private $updatedAt;

    /**
     * @var \DateTime|null
     */
    #[ORM\Column(name: 'removed_at', type: 'datetime', nullable: true)]
    private $removedAt;

    /**
     * @var ArrayCollection<int, Star>
     */
    #[ORM\OneToMany(targetEntity: \Star::class, mappedBy: 'user')]
    private $stars;

    public function __construct()
    {
        $this->uuid = Uuid::uuid4()->toString();
        $this->stars = new ArrayCollection();
    }

    /**
     * Set id.
     *
     * @param int $id
     *
     * @return User
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
     * Set username.
     *
     * @param string $username
     *
     * @return User
     */
    public function setUsername($username)
    {
        $this->username = $username;

        return $this;
    }

    /**
     * Get username.
     *
     * @return string
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * Get uuid.
     *
     * @return string
     */
    public function getUuid()
    {
        return $this->uuid;
    }

    /**
     * Set avatar.
     *
     * @param string $avatar
     *
     * @return User
     */
    public function setAvatar($avatar)
    {
        $this->avatar = $avatar;

        return $this;
    }

    /**
     * Get avatar.
     *
     * @return string
     */
    public function getAvatar()
    {
        return $this->avatar;
    }

    /**
     * Set name.
     *
     * @param string $name
     *
     * @return User
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
        return (string) $this->name;
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

    /**
     * Set updatedAt.
     *
     * @param \DateTime $updatedAt
     *
     * @return User
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

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function timestamps(): void
    {
        if (null === $this->createdAt) {
            $this->createdAt = new \DateTime();
        }
        $this->updatedAt = new \DateTime();
    }

    /**
     * Hydrate a user with data from Github.
     */
    public function hydrateFromGithub(GithubResourceOwner $data): void
    {
        $info = $data->toArray();

        $this->setId($info['id']);
        $this->setUsername($info['login']);
        $this->setAvatar($info['avatar_url']);
        $this->setName($info['name']);
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function getPassword(): ?string
    {
        return '';
    }

    public function getSalt(): ?string
    {
        return null;
    }

    public function eraseCredentials(): void
    {
    }

    /**
     * Set accessToken.
     *
     * @param string $accessToken
     *
     * @return User
     */
    public function setAccessToken($accessToken)
    {
        $this->accessToken = $accessToken;

        return $this;
    }

    /**
     * Get accessToken.
     *
     * @return string
     */
    public function getAccessToken()
    {
        return $this->accessToken;
    }

    /**
     * Set removedAt.
     *
     * @param \DateTime $removedAt
     *
     * @return User
     */
    public function setRemovedAt($removedAt)
    {
        $this->removedAt = $removedAt;

        return $this;
    }

    /**
     * Get removedAt.
     *
     * @return \DateTime|null
     */
    public function getRemovedAt()
    {
        return $this->removedAt;
    }

    /**
     * Trying to determine if the user should be logged out because it has changed or not.
     *
     * @see https://stackoverflow.com/a/47676103/569101
     * @see https://symfony.com/doc/4.4/reference/configuration/security.html#logout-on-user-change
     *
     * @return bool
     */
    public function isEqualTo(UserInterface $user)
    {
        if ($user instanceof self) {
            if ($this->accessToken !== $user->getAccessToken()) {
                return false;
            }

            if ($this->uuid !== $user->getUuid()) {
                return false;
            }
        }

        if ($this->username !== $user->getUserIdentifier()) {
            return false;
        }

        return true;
    }

    public function getUserIdentifier(): string
    {
        return $this->getUsername();
    }
}
