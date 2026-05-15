<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add enforcement_profile column to clearpass_auth_log';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE clearpass_auth_log ADD enforcement_profile VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE clearpass_auth_log DROP COLUMN enforcement_profile');
    }
}
