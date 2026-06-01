<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260601143754 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE snipe_it_server ADD default_subnet_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE snipe_it_server ADD CONSTRAINT FK_A5EC9AF858ADBCDF FOREIGN KEY (default_subnet_id) REFERENCES subnet (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_A5EC9AF858ADBCDF ON snipe_it_server (default_subnet_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE snipe_it_server DROP FOREIGN KEY FK_A5EC9AF858ADBCDF');
        $this->addSql('DROP INDEX IDX_A5EC9AF858ADBCDF ON snipe_it_server');
        $this->addSql('ALTER TABLE snipe_it_server DROP default_subnet_id');
    }
}
