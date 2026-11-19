<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index release dates per repository for dashboard and latest releases queries';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration can only be executed safely on MySQL.');

        $this->skipIf($schema->getTable('version')->hasIndex('repo_created_at_idx'), 'The version.repo_created_at_idx index already exists.');

        $this->addSql('ALTER TABLE version ADD INDEX repo_created_at_idx (repo_id, created_at), ALGORITHM=INPLACE, LOCK=NONE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration can only be executed safely on MySQL.');

        $this->addSql('ALTER TABLE version DROP INDEX repo_created_at_idx, ALGORITHM=INPLACE, LOCK=NONE');
    }
}
