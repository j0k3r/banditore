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
            'published_at' => '19 february 2017',
        ]);

        $manager->persist($version1);
        $manager->flush();

        $this->addReference('version1', $version1);
    }

    /**
     * {@inheritdoc}
     */
    public function getOrder()
    {
        return 40;
    }
}
