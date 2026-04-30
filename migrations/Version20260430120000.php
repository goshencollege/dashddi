<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260430120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add name field to network_interface';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE network_interface ADD name VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE network_interface DROP COLUMN name');
    }
}
