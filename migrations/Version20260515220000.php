<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nas_port_id to clearpass_auth_log';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE clearpass_auth_log ADD nas_port_id VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE clearpass_auth_log DROP COLUMN nas_port_id');
    }
}
