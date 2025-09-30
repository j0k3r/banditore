<?php

namespace App\Tests\Twig;

use App\Twig\RepoVersionExtension;
use PHPUnit\Framework\TestCase;

class RepoVersionExtensionTest extends TestCase
{
    public function test(): void
    {
        $ext = new RepoVersionExtension();

        $this->assertSame('repo_version_extension', $ext->getName());

        $this->assertNull($ext->linkToVersion([]));
        $this->assertNull($ext->linkToVersion(['fullName' => 'test/test']));
        $this->assertNull($ext->linkToVersion(['tagName' => 'v1.0.0']));

        $this->assertSame('https://github.com/test/test/releases/v1.0.0', $ext->linkToVersion(['fullName' => 'test/test', 'tagName' => 'v1.0.0']));
    }

    public function testEncodedTagName(): void
    {
        $ext = new RepoVersionExtension();

        $this->assertSame('repo_version_extension', $ext->getName());

        $this->assertNull($ext->linkToVersion([]));
        $this->assertNull($ext->linkToVersion(['fullName' => 'test/test']));
        $this->assertNull($ext->linkToVersion(['tagName' => '@1.0.0-alpha.1']));

        $this->assertSame('https://github.com/test/test/releases/%401.0.0-alpha.1', $ext->linkToVersion(['fullName' => 'test/test', 'tagName' => '@1.0.0-alpha.1']));
    }
}
