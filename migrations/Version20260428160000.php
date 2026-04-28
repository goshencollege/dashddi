<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260428160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dhcp_server table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE dhcp_server (
            id           INT AUTO_INCREMENT NOT NULL,
            name         VARCHAR(255)  NOT NULL,
            hostname     VARCHAR(255)  NOT NULL,
            ssh_user     VARCHAR(64)   NOT NULL DEFAULT \'root\',
            remote_path  VARCHAR(255)  NOT NULL DEFAULT \'/etc/kea\',
            ssh_key_path VARCHAR(255)  NOT NULL DEFAULT \'/root/.ssh/id_ed25519\',
            description  LONGTEXT      NULL,
            created_at   DATETIME      NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at   DATETIME      NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            created_by   VARCHAR(255)  NULL,
            updated_by   VARCHAR(255)  NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE dhcp_server');
    }
}
