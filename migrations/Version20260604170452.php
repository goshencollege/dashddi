<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260604170452 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dhcp_server CHANGE version_scope version_scope VARCHAR(4) NOT NULL');
        $this->addSql('ALTER TABLE dns_server ADD bind_user VARCHAR(64) NOT NULL');
        $this->addSql('ALTER TABLE sessions CHANGE sess_id sess_id VARBINARY(128) NOT NULL, CHANGE sess_data sess_data LONGBLOB NOT NULL, CHANGE sess_time sess_time INT UNSIGNED NOT NULL, CHANGE sess_lifetime sess_lifetime INT UNSIGNED NOT NULL');
        $this->addSql('CREATE INDEX sess_lifetime_idx ON sessions (sess_lifetime)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dhcp_server CHANGE version_scope version_scope VARCHAR(4) DEFAULT \'both\' NOT NULL');
        $this->addSql('ALTER TABLE dns_server DROP bind_user');
        $this->addSql('DROP INDEX sess_lifetime_idx ON sessions');
        $this->addSql('ALTER TABLE sessions CHANGE sess_id sess_id VARCHAR(128) NOT NULL, CHANGE sess_data sess_data BLOB NOT NULL, CHANGE sess_lifetime sess_lifetime INT NOT NULL, CHANGE sess_time sess_time INT NOT NULL');
    }
}
