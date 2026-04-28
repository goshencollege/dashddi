<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260428130000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE building (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, description VARCHAR(255) DEFAULT NULL, UNIQUE INDEX UNIQ_building_name (name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4');
        // Migrate existing building strings into the new table
        $this->addSql('INSERT INTO building (name) SELECT DISTINCT building FROM host WHERE building IS NOT NULL AND building != \'\'');
        $this->addSql('ALTER TABLE host ADD building_id INT DEFAULT NULL');
        $this->addSql('UPDATE host h JOIN building b ON h.building = b.name SET h.building_id = b.id');
        $this->addSql('ALTER TABLE host DROP COLUMN building');
        $this->addSql('ALTER TABLE host ADD CONSTRAINT FK_host_building FOREIGN KEY (building_id) REFERENCES building (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_host_building ON host (building_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE host DROP FOREIGN KEY FK_host_building');
        $this->addSql('DROP INDEX IDX_host_building ON host');
        $this->addSql('ALTER TABLE host ADD building VARCHAR(100) DEFAULT NULL');
        $this->addSql('UPDATE host h JOIN building b ON h.building_id = b.id SET h.building = b.name');
        $this->addSql('ALTER TABLE host DROP COLUMN building_id');
        $this->addSql('DROP TABLE building');
    }
}
