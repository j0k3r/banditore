<?php

namespace AppBundle\DataFixtures\ORM;

use AppBundle\Entity\Version;
use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\DataFixtures\OrderedFixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;

class LoadVersionData extends AbstractFixture implements OrderedFixtureInterface
{
    public function load(ObjectManager $manager)
    {
        $version1 = new Version($this->getReference('repo1'));
        $version1->hydrateFromGithub([
            'tag_name' => '1.0.0',
            'name' => 'First release',
            'prerelease' => false,
            'message' => 'YAY',
            'published_at' => (date('Y') + 1) . '-10-15T07:49:21Z',
        ]);
        $manager->persist($version1);

        $version2 = new Version($this->getReference('repo2'));
        $version2->hydrateFromGithub([
            'tag_name' => '1.0.21',
            'name' => 'First release',
            'prerelease' => false,
            'message' => 'YAY 555',
            'published_at' => (date('Y') + 1) . '-06-15T07:49:21Z',
        ]);

        $manager->persist($version2);
        $manager->flush();

        $this->addReference('version1', $version1);
        $this->addReference('version2', $version2);
    }

    /**
     * {@inheritdoc}
     */
    public function getOrder()
    {
        return 40;
    }
}
