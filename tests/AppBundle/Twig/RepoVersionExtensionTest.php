<?php

namespace Tests\AppBundle\Twig;

use AppBundle\Twig\RepoVersionExtension;

class RepoVersionExtensionTest extends \PHPUnit\Framework\TestCase
{
    public function test()
    {
        $ext = new RepoVersionExtension();

        $this->assertSame('repo_version_extension', $ext->getName());
        $this->assertCount(1, $ext->getFilters(), 'Extension has only one filter');

        $this->assertNull($ext->linkToVersion([]));
        $this->assertNull($ext->linkToVersion(['fullName' => 'test/test']));
        $this->assertNull($ext->linkToVersion(['tagName' => 'v1.0.0']));

        $this->assertSame('https://github.com/test/test/releases/v1.0.0', $ext->linkToVersion(['fullName' => 'test/test', 'tagName' => 'v1.0.0']));
    }
}
