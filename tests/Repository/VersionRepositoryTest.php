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

    public function testFindForUserPaginatesBeforeLoadingDetails(): void
    {
        $repository = self::getContainer()->get(VersionRepository::class);

        $firstPage = $repository->findForUser(123, 0, 1);
        $secondPage = $repository->findForUser(123, 1, 1);

        $this->assertCount(1, $firstPage);
        $this->assertSame(666, $firstPage[0]['repoId']);
        $this->assertSame('YAY', $firstPage[0]['body']);
        $this->assertSame('test/test', $firstPage[0]['fullName']);
        $this->assertFalse($firstPage[0]['ignoredInFeed']);
        $this->assertCount(1, $secondPage);
        $this->assertSame(555, $secondPage[0]['repoId']);
        $this->assertSame('YAY 555', $secondPage[0]['body']);
        $this->assertSame([], $repository->findForUser(123, 2, 1));
    }

    public function testFindForFeedUserPaginatesBeforeLoadingDetails(): void
    {
        $repository = self::getContainer()->get(VersionRepository::class);

        $firstPage = $repository->findForFeedUser(123, 0, 1);
        $secondPage = $repository->findForFeedUser(123, 1, 1);

        $this->assertCount(1, $firstPage);
        $this->assertSame(666, $firstPage[0]['repoId']);
        $this->assertSame('test/test', $firstPage[0]['fullName']);
        $this->assertSame('YAY', $firstPage[0]['body']);
        $this->assertCount(1, $secondPage);
        $this->assertSame(555, $secondPage[0]['repoId']);
        $this->assertSame('YAY 555', $secondPage[0]['body']);
        $this->assertSame([], $repository->findForFeedUser(123, 2, 1));
    }

    public function testPrereleasesAreFilteredBeforePagination(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $version = new Version($entityManager->getReference(Repo::class, 666));
        $version->hydrateFromGithub([
            'tag_name' => '2.0.0-beta.1',
            'name' => 'Preview',
            'prerelease' => true,
            'message' => 'A preview release',
            'published_at' => '2026-11-19T12:00:00Z',
        ]);
        $entityManager->persist($version);
        $entityManager->flush();
        $repository = self::getContainer()->get(VersionRepository::class);

        $this->assertSame(3, $repository->countForUser(123));
        $this->assertSame(2, $repository->countForUser(123, true));
        $this->assertSame('2.0.0-beta.1', $repository->findForUser(123, 0, 1)[0]['tagName']);
        $this->assertSame('1.0.0', $repository->findForUser(123, 0, 1, true)[0]['tagName']);
        $this->assertSame('1.0.21', $repository->findForUser(123, 1, 1, true)[0]['tagName']);
        $this->assertSame([], $repository->findForUser(123, 2, 1, true));
        $this->assertSame('2.0.0-beta.1', $repository->findForFeedUser(123, 0, 1)[0]['tagName']);
        $this->assertSame('1.0.0', $repository->findForFeedUser(123, 0, 1, true)[0]['tagName']);
        $this->assertSame('1.0.21', $repository->findForFeedUser(123, 1, 1, true)[0]['tagName']);
        $this->assertSame([], $repository->findForFeedUser(123, 2, 1, true));

        self::getContainer()->get(Connection::class)->executeStatement('UPDATE star SET ignored_in_feed = 1 WHERE user_id = 123 AND repo_id = 666');
        $this->assertSame('1.0.21', $repository->findForFeedUser(123, 0, 1, true)[0]['tagName']);
        $this->assertSame(2, $repository->countForUser(123, true));

        self::getContainer()->get(Connection::class)->executeStatement('UPDATE version SET prerelease = 1 WHERE repo_id IN (555, 666)');
        $this->assertSame(0, $repository->countForUser(123, true));
        $this->assertSame([], $repository->findForUser(123, 0, 1, true));
        $this->assertSame([], $repository->findForFeedUser(123, 0, 1, true));
    }

    public function testFindForUserOrdersEqualDatesConsistently(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $version = new Version($entityManager->getReference(Repo::class, 666));
        $version->hydrateFromGithub([
            'tag_name' => '1.0.1',
            'name' => 'Another release',
            'prerelease' => false,
            'message' => 'Same date',
            'published_at' => '2019-10-15T07:49:21Z',
        ]);
        $entityManager->persist($version);
        $entityManager->flush();

        $repository = self::getContainer()->get(VersionRepository::class);

        $this->assertSame('1.0.1', $repository->findForUser(123, 0, 1)[0]['tagName']);
        $this->assertSame('1.0.0', $repository->findForUser(123, 1, 1)[0]['tagName']);
        $this->assertSame('1.0.1', $repository->findForFeedUser(123, 0, 1)[0]['tagName']);
        $this->assertSame('1.0.0', $repository->findForFeedUser(123, 1, 1)[0]['tagName']);
    }

    public function testVersionsAreLimitedToTheUsersSubscriptions(): void
    {
        $repository = self::getContainer()->get(VersionRepository::class);

        $this->assertSame(2, $repository->countForUser(123));
        $this->assertCount(2, $repository->findForUser(123));
        $this->assertSame(0, $repository->countForUser(999999));
        $this->assertSame([], $repository->findForUser(999999));
        $this->assertCount(2, $repository->findForFeedUser(123));
        $this->assertSame([], $repository->findForFeedUser(999999));
    }

    public function testFindForFeedUserExcludesIgnoredStars(): void
    {
        $repository = self::getContainer()->get(VersionRepository::class);

        self::getContainer()->get(Connection::class)->executeStatement('UPDATE star SET ignored_in_feed = 1 WHERE user_id = 123 AND repo_id = 666');

        $dashboardVersions = $repository->findForUser(123);
        $feedVersions = $repository->findForFeedUser(123, 0, 1);

        $this->assertNotEmpty($dashboardVersions);
        $this->assertTrue($dashboardVersions[0]['ignoredInFeed']);
        $this->assertCount(1, $feedVersions);
        $this->assertSame('symfony/symfony', $feedVersions[0]['fullName']);
        $this->assertSame([], $repository->findForFeedUser(123, 1, 1));

        self::getContainer()->get(Connection::class)->executeStatement('UPDATE star SET ignored_in_feed = 1 WHERE user_id = 123');

        $this->assertSame([], $repository->findForFeedUser(123));
    }
}
