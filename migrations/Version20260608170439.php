<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260608170439 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ns_update_source_address to dns_view for per-view nsupdate source address';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dns_view ADD ns_update_source_address VARCHAR(45) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dns_view DROP ns_update_source_address');
    }
}
