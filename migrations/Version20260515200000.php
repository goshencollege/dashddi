<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add role and vlan columns to clearpass_auth_log';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE clearpass_auth_log ADD role VARCHAR(255) DEFAULT NULL, ADD vlan VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE clearpass_auth_log DROP COLUMN role, DROP COLUMN vlan');
    }
}
