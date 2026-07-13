<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260713154709 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace control_url with control_port; reload is now tunnelled over SSH so the control agent need not be exposed externally';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dhcp_server ADD control_port INT UNSIGNED DEFAULT NULL');
        // Preserve existing port numbers by extracting them from the stored URL (e.g. http://1.2.3.4:8000 → 8000)
        $this->addSql("UPDATE dhcp_server SET control_port = CAST(SUBSTRING_INDEX(control_url, ':', -1) AS UNSIGNED) WHERE control_url IS NOT NULL AND control_url REGEXP ':[0-9]+$'");
        $this->addSql('ALTER TABLE dhcp_server DROP control_url');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dhcp_server ADD control_url VARCHAR(255) DEFAULT NULL');
        $this->addSql("UPDATE dhcp_server SET control_url = CONCAT('http://127.0.0.1:', control_port) WHERE control_port IS NOT NULL");
        $this->addSql('ALTER TABLE dhcp_server DROP control_port');
    }
}
