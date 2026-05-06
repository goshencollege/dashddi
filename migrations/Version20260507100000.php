<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260507100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add SMTP settings to app_setting and notification_email to scheduled_task';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE app_setting ADD smtp_host VARCHAR(255) DEFAULT NULL");
        $this->addSql("ALTER TABLE app_setting ADD smtp_port INT DEFAULT 587");
        $this->addSql("ALTER TABLE app_setting ADD smtp_username VARCHAR(255) DEFAULT NULL");
        $this->addSql("ALTER TABLE app_setting ADD smtp_password VARCHAR(255) DEFAULT NULL");
        $this->addSql("ALTER TABLE app_setting ADD smtp_from_email VARCHAR(255) DEFAULT NULL");
        $this->addSql("ALTER TABLE app_setting ADD smtp_from_name VARCHAR(255) DEFAULT NULL");
        $this->addSql("ALTER TABLE app_setting ADD smtp_encryption VARCHAR(10) DEFAULT 'tls'");
        $this->addSql("ALTER TABLE scheduled_task ADD notification_email VARCHAR(255) DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE app_setting DROP COLUMN smtp_host");
        $this->addSql("ALTER TABLE app_setting DROP COLUMN smtp_port");
        $this->addSql("ALTER TABLE app_setting DROP COLUMN smtp_username");
        $this->addSql("ALTER TABLE app_setting DROP COLUMN smtp_password");
        $this->addSql("ALTER TABLE app_setting DROP COLUMN smtp_from_email");
        $this->addSql("ALTER TABLE app_setting DROP COLUMN smtp_from_name");
        $this->addSql("ALTER TABLE app_setting DROP COLUMN smtp_encryption");
        $this->addSql("ALTER TABLE scheduled_task DROP COLUMN notification_email");
    }
}
