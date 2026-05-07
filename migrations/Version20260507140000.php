<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260507140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add unique constraints to ip_address and ipv6_address tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX UNIQ_IP_ADDRESS_ADDR ON ip_address (address)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_IPV6_ADDRESS_ADDR ON ipv6_address (address)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_IP_ADDRESS_ADDR ON ip_address');
        $this->addSql('DROP INDEX UNIQ_IPV6_ADDRESS_ADDR ON ipv6_address');
    }
}
