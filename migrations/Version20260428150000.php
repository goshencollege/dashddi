<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260428150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tag table and host_tag join table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tag (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(50) NOT NULL,
            UNIQUE INDEX UNIQ_389B7835E237E06 (name),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');

        $this->addSql('CREATE TABLE host_tag (
            host_id INT NOT NULL,
            tag_id  INT NOT NULL,
            INDEX IDX_B160D9071A0E9B39 (host_id),
            INDEX IDX_B160D907BAD26311 (tag_id),
            PRIMARY KEY(host_id, tag_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');

        $this->addSql('ALTER TABLE host_tag
            ADD CONSTRAINT FK_B160D9071A0E9B39 FOREIGN KEY (host_id) REFERENCES host (id) ON DELETE CASCADE,
            ADD CONSTRAINT FK_B160D907BAD26311 FOREIGN KEY (tag_id)  REFERENCES tag  (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE host_tag DROP FOREIGN KEY FK_B160D9071A0E9B39');
        $this->addSql('ALTER TABLE host_tag DROP FOREIGN KEY FK_B160D907BAD26311');
        $this->addSql('DROP TABLE host_tag');
        $this->addSql('DROP TABLE tag');
    }
}
