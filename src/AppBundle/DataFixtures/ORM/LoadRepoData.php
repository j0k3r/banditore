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
            'homepage' => 'http://homepage.io',
            'language' => 'Go',
            'owner' => [
                'avatar_url' => 'http://0.0.0.0/test.jpg',
            ],
        ]);
        $manager->persist($repo1);

        $repo2 = new Repo();
        $repo2->hydrateFromGithub([
            'id' => 555,
            'name' => 'symfony',
            'full_name' => 'symfony/symfony',
            'description' => 'The Symfony PHP framework',
            'homepage' => 'http://symfony.com',
            'language' => 'PHP',
            'owner' => [
                'avatar_url' => 'https://avatars2.githubusercontent.com/u/143937?v=3',
            ],
        ]);
        $manager->persist($repo2);

        $manager->flush();

        $this->addReference('repo1', $repo1);
        $this->addReference('repo2', $repo2);
    }

    /**
     * {@inheritdoc}
     */
    public function getOrder()
    {
        return 20;
    }
}
