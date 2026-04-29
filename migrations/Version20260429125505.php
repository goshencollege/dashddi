<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260429125505 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE subnet_dns_view (subnet_id INT NOT NULL, dns_view_id INT NOT NULL, INDEX IDX_155CE538C9CF9478 (subnet_id), INDEX IDX_155CE5389FF5B956 (dns_view_id), PRIMARY KEY (subnet_id, dns_view_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE subnet_dns_view ADD CONSTRAINT FK_155CE538C9CF9478 FOREIGN KEY (subnet_id) REFERENCES subnet (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE subnet_dns_view ADD CONSTRAINT FK_155CE5389FF5B956 FOREIGN KEY (dns_view_id) REFERENCES dns_view (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE subnet_dns_view DROP FOREIGN KEY FK_155CE538C9CF9478');
        $this->addSql('ALTER TABLE subnet_dns_view DROP FOREIGN KEY FK_155CE5389FF5B956');
        $this->addSql('DROP TABLE subnet_dns_view');
    }
}
