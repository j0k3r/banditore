<?php

namespace Application\Migrations;

use Doctrine\DBAL\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * Add homepage & language to the repo entity.
 */
class Version20170222055642 extends AbstractMigration
{
    public function up(Schema $schema)
    {
        $this->abortIf('mysql' !== $this->connection->getDatabasePlatform()->getName(), 'Migration can only be executed safely on \'mysql\'.');

        $repoTable = $schema->getTable('repo');
        $this->skipIf($repoTable->hasColumn('homepage') || $repoTable->hasColumn('language'), 'It seems that you already played this migration.');

        $this->addSql('ALTER TABLE repo ADD homepage VARCHAR(255) DEFAULT NULL, ADD language VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema)
    {
        $this->abortIf('mysql' !== $this->connection->getDatabasePlatform()->getName(), 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE repo DROP homepage, DROP language');
    }
}
