<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260521174942 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE address_block RENAME INDEX idx_b4bee002c9cf9478 TO idx_address_block_subnet_id');
        $this->addSql('CREATE INDEX idx_clearpass_auth_status ON clearpass_auth_log (auth_status)');
        $this->addSql('CREATE INDEX idx_clearpass_auth_role ON clearpass_auth_log (role)');
        $this->addSql('CREATE INDEX idx_clearpass_auth_vlan ON clearpass_auth_log (vlan)');
        $this->addSql('CREATE INDEX idx_clearpass_auth_protocol ON clearpass_auth_log (auth_protocol)');
        $this->addSql('CREATE INDEX idx_clearpass_auth_service ON clearpass_auth_log (service)');
        $this->addSql('CREATE INDEX idx_clearpass_auth_nas_ip ON clearpass_auth_log (nas_ip)');
        $this->addSql('ALTER TABLE clearpass_auth_log RENAME INDEX idx_99d106aeec1450bd TO idx_clearpass_auth_server_id');
        $this->addSql('ALTER TABLE dhcp_lease RENAME INDEX idx_b30ca119c9cf9478 TO idx_dhcp_lease_subnet_id');
        $this->addSql('ALTER TABLE domain_record RENAME INDEX idx_f4579358115f0ee5 TO idx_domain_record_domain_id');
        $this->addSql('ALTER TABLE interface_name RENAME INDEX idx_e72d638ece793eea TO idx_interface_name_network_interface_id');
        $this->addSql('ALTER TABLE interface_name RENAME INDEX idx_e72d638e115f0ee5 TO idx_interface_name_domain_id');
        $this->addSql('ALTER TABLE ip_address RENAME INDEX idx_22ffd58cc9cf9478 TO idx_ip_address_subnet_id');
        $this->addSql('ALTER TABLE ipv6_address RENAME INDEX idx_8a54df17c9cf9478 TO idx_ipv6_address_subnet_id');
        $this->addSql('CREATE INDEX idx_network_interface_ip_address_id ON network_interface (ip_address_id)');
        $this->addSql('CREATE INDEX idx_network_interface_ipv6_address_id ON network_interface (ipv6_address_id)');
        $this->addSql('ALTER TABLE network_interface RENAME INDEX idx_b3518c341fb8d185 TO idx_network_interface_host_id');
        $this->addSql('ALTER TABLE network_interface RENAME INDEX idx_b3518c34c9cf9478 TO idx_network_interface_subnet_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE address_block RENAME INDEX idx_address_block_subnet_id TO IDX_B4BEE002C9CF9478');
        $this->addSql('DROP INDEX idx_clearpass_auth_status ON clearpass_auth_log');
        $this->addSql('DROP INDEX idx_clearpass_auth_role ON clearpass_auth_log');
        $this->addSql('DROP INDEX idx_clearpass_auth_vlan ON clearpass_auth_log');
        $this->addSql('DROP INDEX idx_clearpass_auth_protocol ON clearpass_auth_log');
        $this->addSql('DROP INDEX idx_clearpass_auth_service ON clearpass_auth_log');
        $this->addSql('DROP INDEX idx_clearpass_auth_nas_ip ON clearpass_auth_log');
        $this->addSql('ALTER TABLE clearpass_auth_log RENAME INDEX idx_clearpass_auth_server_id TO IDX_99D106AEEC1450BD');
        $this->addSql('ALTER TABLE dhcp_lease RENAME INDEX idx_dhcp_lease_subnet_id TO IDX_B30CA119C9CF9478');
        $this->addSql('ALTER TABLE domain_record RENAME INDEX idx_domain_record_domain_id TO IDX_F4579358115F0EE5');
        $this->addSql('ALTER TABLE interface_name RENAME INDEX idx_interface_name_network_interface_id TO IDX_E72D638ECE793EEA');
        $this->addSql('ALTER TABLE interface_name RENAME INDEX idx_interface_name_domain_id TO IDX_E72D638E115F0EE5');
        $this->addSql('ALTER TABLE ip_address RENAME INDEX idx_ip_address_subnet_id TO IDX_22FFD58CC9CF9478');
        $this->addSql('ALTER TABLE ipv6_address RENAME INDEX idx_ipv6_address_subnet_id TO IDX_8A54DF17C9CF9478');
        $this->addSql('DROP INDEX idx_network_interface_ip_address_id ON network_interface');
        $this->addSql('DROP INDEX idx_network_interface_ipv6_address_id ON network_interface');
        $this->addSql('ALTER TABLE network_interface RENAME INDEX idx_network_interface_host_id TO IDX_B3518C341FB8D185');
        $this->addSql('ALTER TABLE network_interface RENAME INDEX idx_network_interface_subnet_id TO IDX_B3518C34C9CF9478');
    }
}
