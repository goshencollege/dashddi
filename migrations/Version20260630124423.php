<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260630124423 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE domain_alias (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, domain_id INT NOT NULL, UNIQUE INDEX UNIQ_321743425E237E06 (name), INDEX IDX_32174342115F0EE5 (domain_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE domain_alias ADD CONSTRAINT FK_32174342115F0EE5 FOREIGN KEY (domain_id) REFERENCES domain (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE network_interface CHANGE last_auth_at last_auth_at DATETIME DEFAULT NULL, CHANGE last_dhcp_at last_dhcp_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE domain_alias DROP FOREIGN KEY FK_32174342115F0EE5');
        $this->addSql('DROP TABLE domain_alias');
        $this->addSql('ALTER TABLE network_interface CHANGE last_auth_at last_auth_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE last_dhcp_at last_dhcp_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }
}
