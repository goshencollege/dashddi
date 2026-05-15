<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Set clearpass_auth_log_retention_days default to 30 and update existing rows';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_setting MODIFY clearpass_auth_log_retention_days INT DEFAULT 30');
        $this->addSql('UPDATE app_setting SET clearpass_auth_log_retention_days = 30 WHERE clearpass_auth_log_retention_days = 90');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_setting MODIFY clearpass_auth_log_retention_days INT DEFAULT 90');
    }
}
