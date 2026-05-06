<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260507120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add missing created_by and updated_by columns to the host table';
    }

    public function up(Schema $schema): void
    {
        // These columns were omitted from Version20260427181323 due to an incorrect
        // "partial run" assumption, but they were never actually applied to the DB.
        $this->addSql('ALTER TABLE host ADD created_by VARCHAR(255) DEFAULT NULL, ADD updated_by VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE host DROP created_by, DROP updated_by');
    }
}
