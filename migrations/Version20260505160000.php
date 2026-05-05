<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260505160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add subnet_search JSON column to user_preference';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_preference ADD subnet_search JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_preference DROP COLUMN subnet_search');
    }
}
