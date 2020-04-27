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
        /** @var \AppBundle\Entity\Repo */
        $repo1 = $this->getReference('repo1');
        /** @var \AppBundle\Entity\Repo */
        $repo2 = $this->getReference('repo2');
        /** @var \AppBundle\Entity\Repo */
        $repo3 = $this->getReference('repo3');

        $version1 = new Version($repo1);
        $version1->hydrateFromGithub([
            'tag_name' => '1.0.0',
            'name' => 'First release',
            'prerelease' => false,
            'message' => 'YAY',
            'published_at' => ((int) date('Y') + 1) . '-10-15T07:49:21Z',
        ]);
        $manager->persist($version1);

        $version2 = new Version($repo2);
        $version2->hydrateFromGithub([
            'tag_name' => '1.0.21',
            'name' => 'First release',
            'prerelease' => false,
            'message' => 'YAY 555',
            'published_at' => ((int) date('Y') + 1) . '-06-15T07:49:21Z',
        ]);

        $manager->persist($version2);
        $manager->flush();

        $version3 = new Version($repo3);
        $version3->hydrateFromGithub([
            'tag_name' => '0.0.21',
            'name' => 'Outdated release',
            'prerelease' => false,
            'message' => 'YAY OLD',
            'published_at' => date('Y') . '-06-15T07:49:21Z',
        ]);

        $manager->persist($version3);
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
