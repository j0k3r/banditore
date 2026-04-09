<?php

namespace App\Tests\Repository;

use App\Entity\Repo;
use App\Entity\Star;
use App\Entity\User;
use App\Entity\Version;
use App\Repository\VersionRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class VersionRepositoryTest extends WebTestCase
{
    protected function setUp(): void
    {
        static::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQuery('DELETE FROM App\Entity\Star s WHERE s.user = :userId')
            ->setParameter('userId', 123)
            ->execute();
        $entityManager->createQuery('DELETE FROM App\Entity\Version v WHERE v.repo IN (:repoIds)')
            ->setParameter('repoIds', [555, 666])
            ->execute();

        $user = $entityManager->getReference(User::class, 123);
        $repo666 = $entityManager->getReference(Repo::class, 666);
        $repo555 = $entityManager->getReference(Repo::class, 555);

        $entityManager->persist(new Star($user, $repo666));
        $entityManager->persist(new Star($user, $repo555));

        $version666 = new Version($repo666);
        $version666->hydrateFromGithub([
            'tag_name' => '1.0.0',
            'name' => 'First release',
            'prerelease' => false,
            'message' => 'YAY',
            'published_at' => '2019-10-15T07:49:21Z',
        ]);

        $version555 = new Version($repo555);
        $version555->hydrateFromGithub([
            'tag_name' => '1.0.21',
            'name' => 'First release',
            'prerelease' => false,
            'message' => 'YAY 555',
            'published_at' => '2019-06-15T07:49:21Z',
        ]);

        $entityManager->persist($version666);
        $entityManager->persist($version555);
        $entityManager->flush();
        $entityManager->clear();
    }

    public function testFindForFeedUserExcludesIgnoredStars(): void
    {
        $repository = self::getContainer()->get(VersionRepository::class);

        self::getContainer()->get(Connection::class)->executeStatement('UPDATE star SET ignored_in_feed = 1 WHERE user_id = 123 AND repo_id = 666');

        $dashboardVersions = $repository->findForUser(123);
        $feedVersions = $repository->findForFeedUser(123);

        $this->assertNotEmpty($dashboardVersions);
        $this->assertTrue($dashboardVersions[0]['ignoredInFeed']);
        $this->assertCount(1, $feedVersions);
        $this->assertSame('symfony/symfony', $feedVersions[0]['fullName']);
    }
}
