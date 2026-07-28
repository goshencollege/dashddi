<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260728185210 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ssh_host_key (id INT AUTO_INCREMENT NOT NULL, algorithm VARCHAR(50) NOT NULL, public_key LONGTEXT NOT NULL, host_id INT NOT NULL, INDEX IDX_39B1B8971FB8D185 (host_id), UNIQUE INDEX uniq_ssh_host_key_host_algorithm (host_id, algorithm), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE ssh_host_key ADD CONSTRAINT FK_39B1B8971FB8D185 FOREIGN KEY (host_id) REFERENCES host (id) ON DELETE CASCADE');
        // Migrate existing single-key data: extract algorithm from "algorithm base64data" format
        $this->addSql('INSERT INTO ssh_host_key (host_id, algorithm, public_key) SELECT id, TRIM(SUBSTRING_INDEX(ssh_host_public_key, \' \', 1)), ssh_host_public_key FROM host WHERE ssh_host_public_key IS NOT NULL AND ssh_host_public_key != \'\'');
        $this->addSql('ALTER TABLE host DROP ssh_host_public_key');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ssh_host_key DROP FOREIGN KEY FK_39B1B8971FB8D185');
        $this->addSql('DROP TABLE ssh_host_key');
        $this->addSql('ALTER TABLE host ADD ssh_host_public_key LONGTEXT DEFAULT NULL');
    }
}
