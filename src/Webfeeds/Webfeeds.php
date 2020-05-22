<?php

namespace App\Webfeeds;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Webfeeds.
 *
 * @see http://webfeeds.org/rss/1.0
 */
class Webfeeds
{
    /**
     * @Assert\Url
     *
     * @var string|null
     */
    private $logo;

    /**
     * @Assert\Url
     *
     * @var string|null
     */
    private $icon;

    /**
     * @var string|null
     */
    private $accentColor;

    public function setLogo(?string $logo): self
    {
        $this->logo = $logo;

        return $this;
    }

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setIcon(?string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setAccentColor(?string $accentColor): self
    {
        $this->accentColor = $accentColor;

        return $this;
    }

    public function getAccentColor(): ?string
    {
        return $this->accentColor;
    }
}
