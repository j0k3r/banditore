<?php

namespace App\Twig;

use Twig\Attribute\AsTwigFilter;

/**
 * Took a repo with version information to display a link to that version on Github.
 */
class RepoVersionExtension
{
    #[AsTwigFilter('link_to_version')]
    public function linkToVersion(array $repo): ?string
    {
        if (!isset($repo['fullName']) || !isset($repo['tagName'])) {
            return null;
        }

        return 'https://github.com/' . $repo['fullName'] . '/releases/' . urlencode($repo['tagName']);
    }

    public function getName(): string
    {
        return 'repo_version_extension';
    }
}
