<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260701174335 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE domain_record DROP FOREIGN KEY `FK_F4579358CE793EEA`');
        $this->addSql('ALTER TABLE domain_record ADD CONSTRAINT FK_F4579358CE793EEA FOREIGN KEY (network_interface_id) REFERENCES network_interface (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE domain_record DROP FOREIGN KEY FK_F4579358CE793EEA');
        $this->addSql('ALTER TABLE domain_record ADD CONSTRAINT `FK_F4579358CE793EEA` FOREIGN KEY (network_interface_id) REFERENCES network_interface (id) ON UPDATE NO ACTION ON DELETE CASCADE');
    }
}
