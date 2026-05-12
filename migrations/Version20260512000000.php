<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260512000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Set ON DELETE SET NULL for network_interface.subnet_id FK';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE network_interface DROP FOREIGN KEY FK_B3518C34C9CF9478');
        $this->addSql('ALTER TABLE network_interface ADD CONSTRAINT FK_B3518C34C9CF9478 FOREIGN KEY (subnet_id) REFERENCES subnet (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE network_interface DROP FOREIGN KEY FK_B3518C34C9CF9478');
        $this->addSql('ALTER TABLE network_interface ADD CONSTRAINT FK_B3518C34C9CF9478 FOREIGN KEY (subnet_id) REFERENCES subnet (id)');
    }
}
