<?php

namespace App\Tests\Repository;

use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class UserRepositoryTest extends WebTestCase
{
    protected function setUp(): void
    {
        static::createClient();
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE star SET ignored_in_feed = 0');
    }

    public function testFindByRepoIdsExcludesIgnoredStars(): void
    {
        $repository = self::getContainer()->get(UserRepository::class);

        $this->assertCount(1, $repository->findByRepoIds([666]));

        self::getContainer()->get(Connection::class)->executeStatement('UPDATE star SET ignored_in_feed = 1 WHERE user_id = 123 AND repo_id = 666');

        $this->assertSame([], $repository->findByRepoIds([666]));
    }
}
