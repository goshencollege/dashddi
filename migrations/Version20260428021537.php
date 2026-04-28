<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260428021537 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE dns_view (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(64) NOT NULL, description LONGTEXT DEFAULT NULL, UNIQUE INDEX UNIQ_24615775E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE domain_dns_view (domain_id INT NOT NULL, dns_view_id INT NOT NULL, INDEX IDX_11C85A38115F0EE5 (domain_id), INDEX IDX_11C85A389FF5B956 (dns_view_id), PRIMARY KEY (domain_id, dns_view_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE domain_record_dns_view (domain_record_id INT NOT NULL, dns_view_id INT NOT NULL, INDEX IDX_2C728C5DAB8DA742 (domain_record_id), INDEX IDX_2C728C5D9FF5B956 (dns_view_id), PRIMARY KEY (domain_record_id, dns_view_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE interface_name_dns_view (interface_name_id INT NOT NULL, dns_view_id INT NOT NULL, INDEX IDX_795E5FF486E5974C (interface_name_id), INDEX IDX_795E5FF49FF5B956 (dns_view_id), PRIMARY KEY (interface_name_id, dns_view_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE domain_dns_view ADD CONSTRAINT FK_11C85A38115F0EE5 FOREIGN KEY (domain_id) REFERENCES domain (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE domain_dns_view ADD CONSTRAINT FK_11C85A389FF5B956 FOREIGN KEY (dns_view_id) REFERENCES dns_view (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE domain_record_dns_view ADD CONSTRAINT FK_2C728C5DAB8DA742 FOREIGN KEY (domain_record_id) REFERENCES domain_record (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE domain_record_dns_view ADD CONSTRAINT FK_2C728C5D9FF5B956 FOREIGN KEY (dns_view_id) REFERENCES dns_view (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE interface_name_dns_view ADD CONSTRAINT FK_795E5FF486E5974C FOREIGN KEY (interface_name_id) REFERENCES interface_name (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE interface_name_dns_view ADD CONSTRAINT FK_795E5FF49FF5B956 FOREIGN KEY (dns_view_id) REFERENCES dns_view (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE domain_dns_view DROP FOREIGN KEY FK_11C85A38115F0EE5');
        $this->addSql('ALTER TABLE domain_dns_view DROP FOREIGN KEY FK_11C85A389FF5B956');
        $this->addSql('ALTER TABLE domain_record_dns_view DROP FOREIGN KEY FK_2C728C5DAB8DA742');
        $this->addSql('ALTER TABLE domain_record_dns_view DROP FOREIGN KEY FK_2C728C5D9FF5B956');
        $this->addSql('ALTER TABLE interface_name_dns_view DROP FOREIGN KEY FK_795E5FF486E5974C');
        $this->addSql('ALTER TABLE interface_name_dns_view DROP FOREIGN KEY FK_795E5FF49FF5B956');
        $this->addSql('DROP TABLE dns_view');
        $this->addSql('DROP TABLE domain_dns_view');
        $this->addSql('DROP TABLE domain_record_dns_view');
        $this->addSql('DROP TABLE interface_name_dns_view');
    }
}
