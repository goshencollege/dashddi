<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260513184948 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX IDX_API_TOKEN_OWNER ON api_token');
        $this->addSql('ALTER TABLE api_token CHANGE expires_at expires_at DATETIME DEFAULT NULL, CHANGE last_used_at last_used_at DATETIME DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE api_token RENAME INDEX uniq_api_token_hash TO UNIQ_7BA2F5EBB3BC57DA');
        $this->addSql('ALTER TABLE app_setting CHANGE smtp_port smtp_port INT DEFAULT NULL');
        $this->addSql('ALTER TABLE backup_setting CHANGE destination_type destination_type VARCHAR(10) NOT NULL, CHANGE decrypt_fields decrypt_fields TINYINT NOT NULL, CHANGE include_encryption_key include_encryption_key TINYINT NOT NULL, CHANGE encrypt_backup encrypt_backup TINYINT NOT NULL, CHANGE retention_count retention_count INT NOT NULL, CHANGE exclude_dhcp_leases exclude_dhcp_leases TINYINT NOT NULL');
        $this->addSql('ALTER TABLE dhcp_lease CHANGE lease_start lease_start DATETIME NOT NULL, CHANGE lease_expires lease_expires DATETIME DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE dhcp_lease RENAME INDEX idx_dhcp_lease_subnet TO IDX_B30CA119C9CF9478');
        $this->addSql('ALTER TABLE dns_server CHANGE server_type server_type VARCHAR(16) NOT NULL');
        $this->addSql('ALTER TABLE dnssec_ksk_rollover RENAME INDEX idx_575eabb7_subnet TO IDX_575EABB7C9CF9478');
        $this->addSql('ALTER TABLE ip_address RENAME INDEX uniq_ip_address_addr TO UNIQ_22FFD58CD4E6F81');
        $this->addSql('ALTER TABLE ipv6_address RENAME INDEX uniq_ipv6_address_addr TO UNIQ_8A54DF17D4E6F81');
        $this->addSql('ALTER TABLE push_log CHANGE started_at started_at DATETIME NOT NULL, CHANGE completed_at completed_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE radius_client CHANGE enabled enabled TINYINT NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE saml_provider ADD created_by VARCHAR(255) DEFAULT NULL, ADD updated_by VARCHAR(255) DEFAULT NULL, CHANGE is_active is_active TINYINT NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE scheduled_task CHANGE enabled enabled TINYINT NOT NULL, CHANGE last_run_at last_run_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE subnet_tag RENAME INDEX idx_subnet_tag_subnet TO IDX_364AE98AC9CF9478');
        $this->addSql('ALTER TABLE subnet_tag RENAME INDEX idx_subnet_tag_tag TO IDX_364AE98ABAD26311');
        $this->addSql('ALTER TABLE user_preference CHANGE host_view_mode host_view_mode VARCHAR(16) NOT NULL, CHANGE subnet_view_mode subnet_view_mode VARCHAR(16) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE api_token CHANGE expires_at expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE last_used_at last_used_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX IDX_API_TOKEN_OWNER ON api_token (owner_identifier)');
        $this->addSql('ALTER TABLE api_token RENAME INDEX uniq_7ba2f5ebb3bc57da TO UNIQ_API_TOKEN_HASH');
        $this->addSql('ALTER TABLE app_setting CHANGE smtp_port smtp_port INT DEFAULT 587');
        $this->addSql('ALTER TABLE backup_setting CHANGE destination_type destination_type VARCHAR(10) DEFAULT \'local\' NOT NULL, CHANGE decrypt_fields decrypt_fields TINYINT DEFAULT 0 NOT NULL, CHANGE include_encryption_key include_encryption_key TINYINT DEFAULT 0 NOT NULL, CHANGE encrypt_backup encrypt_backup TINYINT DEFAULT 0 NOT NULL, CHANGE exclude_dhcp_leases exclude_dhcp_leases TINYINT DEFAULT 0 NOT NULL, CHANGE retention_count retention_count INT DEFAULT 10 NOT NULL');
        $this->addSql('ALTER TABLE dhcp_lease CHANGE lease_start lease_start DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE lease_expires lease_expires DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE dhcp_lease RENAME INDEX idx_b30ca119c9cf9478 TO idx_dhcp_lease_subnet');
        $this->addSql('ALTER TABLE dns_server CHANGE server_type server_type VARCHAR(16) DEFAULT \'primary\' NOT NULL');
        $this->addSql('ALTER TABLE dnssec_ksk_rollover RENAME INDEX idx_575eabb7c9cf9478 TO IDX_575EABB7_subnet');
        $this->addSql('ALTER TABLE ip_address RENAME INDEX uniq_22ffd58cd4e6f81 TO UNIQ_IP_ADDRESS_ADDR');
        $this->addSql('ALTER TABLE ipv6_address RENAME INDEX uniq_8a54df17d4e6f81 TO UNIQ_IPV6_ADDRESS_ADDR');
        $this->addSql('ALTER TABLE push_log CHANGE started_at started_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE completed_at completed_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE radius_client CHANGE enabled enabled TINYINT DEFAULT 1 NOT NULL, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE saml_provider DROP created_by, DROP updated_by, CHANGE is_active is_active TINYINT DEFAULT 0 NOT NULL, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE scheduled_task CHANGE enabled enabled TINYINT DEFAULT 0 NOT NULL, CHANGE last_run_at last_run_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE subnet_tag RENAME INDEX idx_364ae98ac9cf9478 TO IDX_subnet_tag_subnet');
        $this->addSql('ALTER TABLE subnet_tag RENAME INDEX idx_364ae98abad26311 TO IDX_subnet_tag_tag');
        $this->addSql('ALTER TABLE user_preference CHANGE host_view_mode host_view_mode VARCHAR(16) DEFAULT \'host\' NOT NULL, CHANGE subnet_view_mode subnet_view_mode VARCHAR(16) DEFAULT \'name\' NOT NULL');
    }
}
