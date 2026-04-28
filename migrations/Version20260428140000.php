<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260428140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add vrf table and vrf_id FK on subnet';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE vrf (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(100) NOT NULL,
            route_distinguisher VARCHAR(50) DEFAULT NULL,
            description VARCHAR(255) DEFAULT NULL,
            UNIQUE INDEX UNIQ_657D5F265E237E06 (name),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');

        $this->addSql('ALTER TABLE subnet ADD vrf_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE subnet ADD CONSTRAINT FK_B54A7C1AE2D68CF FOREIGN KEY (vrf_id) REFERENCES vrf (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_B54A7C1AE2D68CF ON subnet (vrf_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subnet DROP FOREIGN KEY FK_B54A7C1AE2D68CF');
        $this->addSql('DROP INDEX IDX_B54A7C1AE2D68CF ON subnet');
        $this->addSql('ALTER TABLE subnet DROP COLUMN vrf_id');
        $this->addSql('DROP TABLE vrf');
    }
}
