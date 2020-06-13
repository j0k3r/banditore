<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Enforce some relations to not be null.
 */
final class Version20200613153754 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce some relations to not be null';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf('mysql' !== $this->connection->getDatabasePlatform()->getName(), 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE star CHANGE user_id user_id INT NOT NULL, CHANGE repo_id repo_id INT NOT NULL');
        $this->addSql('ALTER TABLE version CHANGE repo_id repo_id INT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf('mysql' !== $this->connection->getDatabasePlatform()->getName(), 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE star CHANGE user_id user_id INT DEFAULT NULL, CHANGE repo_id repo_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE version CHANGE repo_id repo_id INT DEFAULT NULL');
    }
}
