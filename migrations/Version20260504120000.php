<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260504120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_container flag to subnet';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subnet ADD is_container TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subnet DROP COLUMN is_container');
    }
}
