<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260429192104 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE domain DROP dnssec_inline_signing');
        $this->addSql('ALTER TABLE subnet DROP dnssec_inline_signing');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE domain ADD dnssec_inline_signing TINYINT NOT NULL');
        $this->addSql('ALTER TABLE subnet ADD dnssec_inline_signing TINYINT NOT NULL');
    }
}
