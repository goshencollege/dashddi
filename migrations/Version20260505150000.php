<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260505150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add subnet_tag join table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE subnet_tag (
            subnet_id INT NOT NULL,
            tag_id INT NOT NULL,
            INDEX IDX_subnet_tag_subnet (subnet_id),
            INDEX IDX_subnet_tag_tag (tag_id),
            PRIMARY KEY(subnet_id, tag_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci ENGINE = InnoDB');

        $this->addSql('ALTER TABLE subnet_tag
            ADD CONSTRAINT FK_subnet_tag_subnet FOREIGN KEY (subnet_id) REFERENCES subnet (id) ON DELETE CASCADE,
            ADD CONSTRAINT FK_subnet_tag_tag FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subnet_tag DROP FOREIGN KEY FK_subnet_tag_subnet');
        $this->addSql('ALTER TABLE subnet_tag DROP FOREIGN KEY FK_subnet_tag_tag');
        $this->addSql('DROP TABLE subnet_tag');
    }
}
