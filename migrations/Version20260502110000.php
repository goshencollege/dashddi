<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260502110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Expand controlPassword to TEXT to accommodate encrypted values';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dhcp_server MODIFY control_password LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dhcp_server MODIFY control_password VARCHAR(255) DEFAULT NULL');
    }
}
