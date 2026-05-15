<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add clearpass_auth_log table, last_auth_log_pull on clearpass_server, and clearpass_auth_log_retention_days on app_setting';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE clearpass_server ADD last_auth_log_pull DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');

        $this->addSql('ALTER TABLE app_setting ADD clearpass_auth_log_retention_days INT DEFAULT 90');

        $this->addSql('CREATE TABLE clearpass_auth_log (
            id INT AUTO_INCREMENT NOT NULL,
            clearpass_server_id INT DEFAULT NULL,
            network_interface_id INT DEFAULT NULL,
            session_id VARCHAR(255) NOT NULL,
            mac_address VARCHAR(17) NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            username VARCHAR(255) DEFAULT NULL,
            service VARCHAR(255) DEFAULT NULL,
            auth_status VARCHAR(50) DEFAULT NULL,
            auth_protocol VARCHAR(100) DEFAULT NULL,
            nas_ip VARCHAR(45) DEFAULT NULL,
            auth_timestamp DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_clearpass_auth_mac (mac_address),
            INDEX idx_clearpass_auth_timestamp (auth_timestamp),
            INDEX IDX_clearpass_server (clearpass_server_id),
            INDEX IDX_clearpass_iface (network_interface_id),
            UNIQUE INDEX uniq_clearpass_auth_session (clearpass_server_id, session_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE clearpass_auth_log
            ADD CONSTRAINT FK_clearpass_auth_server
                FOREIGN KEY (clearpass_server_id) REFERENCES clearpass_server (id) ON DELETE SET NULL,
            ADD CONSTRAINT FK_clearpass_auth_iface
                FOREIGN KEY (network_interface_id) REFERENCES network_interface (id) ON DELETE SET NULL
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE clearpass_auth_log DROP FOREIGN KEY FK_clearpass_auth_server');
        $this->addSql('ALTER TABLE clearpass_auth_log DROP FOREIGN KEY FK_clearpass_auth_iface');
        $this->addSql('DROP TABLE clearpass_auth_log');
        $this->addSql('ALTER TABLE clearpass_server DROP COLUMN last_auth_log_pull');
        $this->addSql('ALTER TABLE app_setting DROP COLUMN clearpass_auth_log_retention_days');
    }
}
