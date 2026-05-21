<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260521183307 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop auth_status column from clearpass_auth_log';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_clearpass_auth_status ON clearpass_auth_log');
        $this->addSql('ALTER TABLE clearpass_auth_log DROP auth_status');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE clearpass_auth_log ADD auth_status VARCHAR(50) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_clearpass_auth_status ON clearpass_auth_log (auth_status)');
    }
}
