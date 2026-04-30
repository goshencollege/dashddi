<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260430110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add host_view_mode preference to user_preference';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE user_preference ADD host_view_mode VARCHAR(16) NOT NULL DEFAULT 'host'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_preference DROP COLUMN host_view_mode');
    }
}
