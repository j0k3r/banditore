<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add homepage & language to the repo entity.
 */
final class Version20170222055642 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add homepage & language to the repo entity.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration can only be executed safely on MySQL.');

        $repoTable = $schema->getTable('repo');
        $this->skipIf($repoTable->hasColumn('homepage') || $repoTable->hasColumn('language'), 'It seems that you already played this migration.');

        $this->addSql('ALTER TABLE repo ADD homepage VARCHAR(255) DEFAULT NULL, ADD language VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration can only be executed safely on MySQL.');

        $this->addSql('ALTER TABLE repo DROP homepage, DROP language');
    }
}
