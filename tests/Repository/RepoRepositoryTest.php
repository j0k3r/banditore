<?php

namespace App\Tests\Repository;

use App\Entity\Repo;
use App\Entity\Star;
use App\Entity\User;
use App\Repository\RepoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class RepoRepositoryTest extends KernelTestCase
{
    public function testFindAllForReleaseRequiresSubscribersAndExcludesRemovedRepos(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeStatement('DELETE FROM star WHERE repo_id IN (444, 555, 666)');
            $connection->executeStatement('UPDATE repo SET removed_at = NULL WHERE id IN (444, 666)');
            $connection->executeStatement('UPDATE repo SET removed_at = CURRENT_TIMESTAMP WHERE id = 555');

            $user = $entityManager->getReference(User::class, 123);
            $otherUser = new User();
            $otherUser->setId(999001);
            $otherUser->setUsername('other-subscriber');
            $otherUser->setName('Other subscriber');
            $otherUser->setAccessToken('test-token');
            $otherUser->setAvatar('https://example.com/avatar.jpg');
            $entityManager->persist($otherUser);

            $activeRepo = $entityManager->getReference(Repo::class, 666);
            $ignoredStar = new Star($user, $activeRepo);
            $ignoredStar->setIgnoredInFeed(true);
            $entityManager->persist($ignoredStar);
            $otherStar = new Star($otherUser, $activeRepo);
            $entityManager->persist($otherStar);
            $entityManager->persist(new Star($user, $entityManager->getReference(Repo::class, 555)));
            $entityManager->flush();

            $repository = self::getContainer()->get(RepoRepository::class);
            $repoIds = $repository->findAllForRelease();

            $this->assertContains(666, $repoIds);
            $this->assertNotContains(444, $repoIds, 'A repository without subscribers is not scheduled.');
            $this->assertNotContains(555, $repoIds, 'A removed repository is not scheduled even with subscribers.');
            $this->assertSame(1, array_count_values($repoIds)[666], 'Multiple subscribers do not duplicate a repository.');
            $this->assertNotNull($repository->find(444), 'Unsubscribed repositories are retained.');

            $entityManager->remove($otherStar);
            $entityManager->flush();
            $this->assertContains(666, $repository->findAllForRelease(), 'Ignoring a repository in the feed does not unsubscribe it.');
        } finally {
            $connection->rollBack();
            $entityManager->clear();
        }
    }
}
