<?php

namespace Tests\AppBundle\Twig;

use AppBundle\Twig\RepoVersionExtension;

class RepoVersionExtensionTest extends \PHPUnit_Framework_TestCase
{
    public function test()
    {
        $ext = new RepoVersionExtension();

        $this->assertSame('repo_verson_extension', $ext->getName());

        $this->assertNull($ext->linkToVersion([]));
        $this->assertNull($ext->linkToVersion(['fullName' => 'test/test']));
        $this->assertNull($ext->linkToVersion(['tagName' => 'v1.0.0']));

        $this->assertSame('https://github.com/test/test/releases/v1.0.0', $ext->linkToVersion(['fullName' => 'test/test', 'tagName' => 'v1.0.0']));
    }
}
