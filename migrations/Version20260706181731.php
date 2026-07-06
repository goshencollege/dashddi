<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260706181731 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reverse zone aggregation flags to subnet table';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE subnet ADD reverse_zone_aggregates_v4 TINYINT DEFAULT 0 NOT NULL, ADD reverse_zone_aggregates_v6 TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE subnet DROP reverse_zone_aggregates_v4, DROP reverse_zone_aggregates_v6');
    }
}
