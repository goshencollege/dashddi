<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260513030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add timezone to app_setting';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_setting ADD timezone VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_setting DROP COLUMN timezone');
    }
}
