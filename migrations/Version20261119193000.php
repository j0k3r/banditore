<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a user preference to hide pre-releases from the dashboard and RSS feed';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('user');
        $this->skipIf($table->hasColumn('hide_prereleases'), 'The user.hide_prereleases column already exists.');

        $tableName = $this->connection->getDatabasePlatform()->quoteSingleIdentifier('user');
        $this->addSql('ALTER TABLE ' . $tableName . ' ADD hide_prereleases BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $tableName = $this->connection->getDatabasePlatform()->quoteSingleIdentifier('user');
        $this->addSql('ALTER TABLE ' . $tableName . ' DROP hide_prereleases');
    }
}
