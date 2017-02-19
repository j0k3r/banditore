<?php

namespace AppBundle\Twig;

/**
 * Took a repo with version information to display a link to that version on Github.
 */
class RepoVersionExtension extends \Twig_Extension
{
    public function getFilters()
    {
        return [
            new \Twig_SimpleFilter('link_to_version', [$this, 'linkToVersion']),
        ];
    }

    public function linkToVersion(array $repo)
    {
        if (!isset($repo['fullName']) || !isset($repo['tagName'])) {
            return;
        }

        return 'https://github.com/' . $repo['fullName'] . '/releases/' . $repo['tagName'];
    }

    public function getName()
    {
        return 'repo_verson_extension';
    }
}
