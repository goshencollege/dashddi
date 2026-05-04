<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260504130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add subnet_view_mode to user_preference';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE user_preference ADD subnet_view_mode VARCHAR(16) NOT NULL DEFAULT 'name'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_preference DROP COLUMN subnet_view_mode');
    }
}
