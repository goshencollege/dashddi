<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260727141310 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add audit columns to api_token for activity log tracking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE api_token ADD updated_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00', ADD created_by VARCHAR(255) DEFAULT NULL, ADD updated_by VARCHAR(255) DEFAULT NULL");
        $this->addSql('UPDATE api_token SET updated_at = created_at');
        $this->addSql('ALTER TABLE api_token MODIFY updated_at DATETIME NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE api_token DROP updated_at, DROP created_by, DROP updated_by');
    }
}
