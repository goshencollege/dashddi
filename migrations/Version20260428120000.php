<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260428120000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE host ADD building VARCHAR(100) DEFAULT NULL, ADD room VARCHAR(50) DEFAULT NULL');
        $this->addSql('UPDATE host SET building = location WHERE location IS NOT NULL');
        $this->addSql('ALTER TABLE host DROP COLUMN location');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE host ADD location VARCHAR(255) DEFAULT NULL');
        $this->addSql('UPDATE host SET location = CONCAT_WS(\' - \', building, room) WHERE building IS NOT NULL');
        $this->addSql('ALTER TABLE host DROP COLUMN building, DROP COLUMN room');
    }
}
