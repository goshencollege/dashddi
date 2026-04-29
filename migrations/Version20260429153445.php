<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260429153445 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE
              dhcp_server
            ADD
              ssh_private_key LONGTEXT DEFAULT NULL,
            ADD
              ssh_public_key LONGTEXT DEFAULT NULL,
            DROP
              ssh_key_path
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              dns_server
            ADD
              ssh_private_key LONGTEXT DEFAULT NULL,
            ADD
              ssh_public_key LONGTEXT DEFAULT NULL,
            DROP
              ssh_key_path
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE
              dhcp_server
            ADD
              ssh_key_path VARCHAR(255) NOT NULL,
            DROP
              ssh_private_key,
            DROP
              ssh_public_key
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              dns_server
            ADD
              ssh_key_path VARCHAR(255) NOT NULL,
            DROP
              ssh_private_key,
            DROP
              ssh_public_key
        SQL);
    }
}
