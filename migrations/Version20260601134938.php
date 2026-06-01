<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260601134938 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE saml_provider CHANGE session_lifetime_minutes session_lifetime_minutes INT NOT NULL');
        $this->addSql('ALTER TABLE snipe_it_server ADD vlan_override_custom_field VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE saml_provider CHANGE session_lifetime_minutes session_lifetime_minutes INT DEFAULT 30 NOT NULL');
        $this->addSql('ALTER TABLE snipe_it_server DROP vlan_override_custom_field');
    }
}
