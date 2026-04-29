<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260429130019 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE subnet ADD soa_nameserver VARCHAR(255) DEFAULT NULL, ADD soa_email VARCHAR(255) DEFAULT NULL, ADD soa_refresh INT DEFAULT NULL, ADD soa_retry INT DEFAULT NULL, ADD soa_expire INT DEFAULT NULL, ADD soa_ttl INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE subnet DROP soa_nameserver, DROP soa_email, DROP soa_refresh, DROP soa_retry, DROP soa_expire, DROP soa_ttl');
    }
}
