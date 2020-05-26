<?php

namespace App\DataFixtures;

use App\Entity\Repo;
use App\Entity\Star;
use App\Entity\User;
use App\Entity\Version;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\Persistence\ObjectManager;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $this->loadUsers($manager);
        $this->loadRepos($manager);
        $this->loadStars($manager);
        $this->loadVersions($manager);
    }

    private function loadUsers(ObjectManager $manager): void
    {
        $user1 = new User();
        $user1->setId(123);
        $user1->setUsername('admin');
        $user1->setName('Bob');
        $user1->setAccessToken('1234567890');
        $user1->setAvatar('http://0.0.0.0/avatar.jpg');

        $manager->persist($user1);
        $manager->flush();

        $this->addReference('user1', $user1);
    }

    private function loadRepos(ObjectManager $manager): void
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

        $repo3 = new Repo();
        $repo3->hydrateFromGithub([
            'id' => 444,
            'name' => 'graby',
            'full_name' => 'j0k3r/graby',
            'description' => 'graby',
            'homepage' => 'http://graby.io',
            'language' => 'PHP',
            'owner' => [
                'avatar_url' => 'http://0.0.0.0/graby.jpg',
            ],
        ]);
        $manager->persist($repo3);

        $manager->flush();

        $this->addReference('repo1', $repo1);
        $this->addReference('repo2', $repo2);
        $this->addReference('repo3', $repo3);
    }

    private function loadStars(ObjectManager $manager): void
    {
        /** @var \App\Entity\User */
        $user1 = $this->getReference('user1');
        /** @var \App\Entity\Repo */
        $repo1 = $this->getReference('repo1');
        /** @var \App\Entity\Repo */
        $repo2 = $this->getReference('repo2');

        $star1 = new Star($user1, $repo1);
        $star2 = new Star($user1, $repo2);

        $manager->persist($star1);
        $manager->persist($star2);
        $manager->flush();

        $this->addReference('star1', $star1);
        $this->addReference('star2', $star2);
    }

    private function loadVersions(ObjectManager $manager): void
    {
        /** @var \App\Entity\Repo */
        $repo1 = $this->getReference('repo1');
        /** @var \App\Entity\Repo */
        $repo2 = $this->getReference('repo2');
        /** @var \App\Entity\Repo */
        $repo3 = $this->getReference('repo3');

        $version1 = new Version($repo1);
        $version1->hydrateFromGithub([
            'tag_name' => '1.0.0',
            'name' => 'First release',
            'prerelease' => false,
            'message' => 'YAY',
            'published_at' => '2019-10-15T07:49:21Z',
        ]);
        $manager->persist($version1);

        $version2 = new Version($repo2);
        $version2->hydrateFromGithub([
            'tag_name' => '1.0.21',
            'name' => 'First release',
            'prerelease' => false,
            'message' => 'YAY 555',
            'published_at' => '2019-06-15T07:49:21Z',
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
}
