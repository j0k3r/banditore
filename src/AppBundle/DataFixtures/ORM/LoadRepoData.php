<?php

namespace AppBundle\DataFixtures\ORM;

use AppBundle\Entity\Repo;
use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\DataFixtures\OrderedFixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;

class LoadRepoData extends AbstractFixture implements OrderedFixtureInterface
{
    public function load(ObjectManager $manager)
    {
        $repo1 = new Repo();
        $repo1->hydrateFromGithub([
            'id' => 666,
            'name' => 'test',
            'full_name' => 'test/test',
            'description' => 'This is a test repo',
            'owner' => [
                'avatar_url' => 'http://0.0.0.0/test.jpg',
            ],
        ]);

        $manager->persist($repo1);
        $manager->flush();

        $this->addReference('repo1', $repo1);
    }

    /**
     * {@inheritdoc}
     */
    public function getOrder()
    {
        return 20;
    }
}
