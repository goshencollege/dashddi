<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260519185352 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add indexes on deleted_at for host and network_interface to speed up soft-delete filter queries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_host_deleted_at ON host (deleted_at)');
        $this->addSql('CREATE INDEX idx_network_interface_deleted_at ON network_interface (deleted_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_host_deleted_at ON host');
        $this->addSql('DROP INDEX idx_network_interface_deleted_at ON network_interface');
    }
}
