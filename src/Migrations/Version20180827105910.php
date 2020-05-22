<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mark a repo as removed to avoid checking for new version in the future.
 */
final class Version20180827105910 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mark a repo as removed to avoid checking for new version in the future.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf('mysql' !== $this->connection->getDatabasePlatform()->getName(), 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE repo ADD removed_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf('mysql' !== $this->connection->getDatabasePlatform()->getName(), 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE repo DROP removed_at');
    }
}
