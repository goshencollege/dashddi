<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260507110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add default_new_subnet_lease_retention_days to app_setting';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE app_setting ADD default_new_subnet_lease_retention_days INT DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE app_setting DROP COLUMN default_new_subnet_lease_retention_days");
    }
}
