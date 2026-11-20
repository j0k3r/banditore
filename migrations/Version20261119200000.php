<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cover pre-release filtering in the dashboard and feed index';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration can only be executed safely on MySQL.');

        $this->skipIf(['repo_id', 'created_at', 'prerelease'] === $schema->getTable('version')->getIndex('repo_created_at_idx')->getColumns(), 'The version.repo_created_at_idx index already covers pre-release filtering.');

        $this->addSql('ALTER TABLE version DROP INDEX repo_created_at_idx, ADD INDEX repo_created_at_idx (repo_id, created_at, prerelease), ALGORITHM=INPLACE, LOCK=NONE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration can only be executed safely on MySQL.');

        $this->addSql('ALTER TABLE version DROP INDEX repo_created_at_idx, ADD INDEX repo_created_at_idx (repo_id, created_at), ALGORITHM=INPLACE, LOCK=NONE');
    }
}
