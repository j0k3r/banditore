<?php

namespace App\Tests\Migrations;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\Exception\SkipMigration;
use DoctrineMigrations\Version20261119193000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once \dirname(__DIR__, 2) . '/migrations/Version20261119193000.php';

class PrereleasePreferenceMigrationTest extends TestCase
{
    #[DataProvider('platforms')]
    public function testAddsAndDropsThePreference(string $driver, string $version, string $tableName): void
    {
        $connection = DriverManager::getConnection(['driver' => $driver, 'serverVersion' => $version]);
        $schema = new Schema([new Table('user', [new Column('id', Types::INTEGER)])]);
        $up = new Version20261119193000($connection, new NullLogger());
        $up->up($schema);

        $this->assertCount(1, $up->getSql());
        $this->assertSame('ALTER TABLE ' . $tableName . ' ADD hide_prereleases BOOLEAN DEFAULT FALSE NOT NULL', $up->getSql()[0]->getStatement());

        $down = new Version20261119193000($connection, new NullLogger());
        $down->down($schema);

        $this->assertCount(1, $down->getSql());
        $this->assertSame('ALTER TABLE ' . $tableName . ' DROP hide_prereleases', $down->getSql()[0]->getStatement());
    }

    #[DataProvider('platforms')]
    public function testSkipsAnExistingColumn(string $driver, string $version, string $tableName): void
    {
        $connection = DriverManager::getConnection(['driver' => $driver, 'serverVersion' => $version]);
        $schema = new Schema([new Table('user', [new Column('hide_prereleases', Types::BOOLEAN)])]);
        $migration = new Version20261119193000($connection, new NullLogger());

        $this->expectException(SkipMigration::class);
        $migration->up($schema);
    }

    public static function platforms(): iterable
    {
        yield 'MySQL' => ['pdo_mysql', '8.0.0', '`user`'];
        yield 'MariaDB' => ['pdo_mysql', '12.3.0-MariaDB', '`user`'];
        yield 'PostgreSQL' => ['pdo_pgsql', '18.0', '"user"'];
    }
}
