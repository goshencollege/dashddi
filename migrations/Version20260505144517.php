<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260505144517 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalise all table collations to utf8mb4_0900_ai_ci to prevent collation-mismatch errors on cross-table comparisons';
    }

    public function up(Schema $schema): void
    {
        foreach (['dhcp_lease', 'dhcp_server', 'scheduled_task', 'tag', 'vrf'] as $table) {
            $this->addSql(
                "ALTER TABLE `$table` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['dhcp_lease', 'dhcp_server', 'scheduled_task', 'tag', 'vrf'] as $table) {
            $this->addSql(
                "ALTER TABLE `$table` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            );
        }
    }
}
