<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260521184336 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add session_lifetime_minutes to saml_provider';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE saml_provider ADD session_lifetime_minutes INT NOT NULL DEFAULT 30');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE saml_provider DROP session_lifetime_minutes');
    }
}
