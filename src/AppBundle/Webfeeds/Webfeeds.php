<?php

namespace AppBundle\Webfeeds;

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
     */
    private $logo;

    /**
     * @Assert\Url
     */
    private $icon;

    private $accentColor;

    public function setLogo($logo)
    {
        $this->logo = $logo;

        return $this;
    }

    public function getLogo()
    {
        return $this->logo;
    }

    public function setIcon($icon)
    {
        $this->icon = $icon;

        return $this;
    }

    public function getIcon()
    {
        return $this->icon;
    }

    public function setAccentColor($accentColor)
    {
        $this->accentColor = $accentColor;

        return $this;
    }

    public function getAccentColor()
    {
        return $this->accentColor;
    }
}
