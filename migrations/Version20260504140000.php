<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260504140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_canonical flag to interface_name for reverse DNS designation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE interface_name ADD is_canonical TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE interface_name DROP COLUMN is_canonical');
    }
}
