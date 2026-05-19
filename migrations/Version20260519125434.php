<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260519125434 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add adopted flag to snipe_it_asset_link to distinguish pre-existing hosts from sync-created ones';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE snipe_it_asset_link ADD adopted TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE snipe_it_asset_link DROP adopted');
    }
}
