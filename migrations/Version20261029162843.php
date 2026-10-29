<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029162843 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cascade-delete host API tokens with their host and remove tokens orphaned by earlier host purges';
    }

    public function up(Schema $schema): void
    {
        // Under ON DELETE SET NULL, purging a host left its token behind with host_id NULL,
        // turning it into an (inert) general token. Host tokens are the only ones created
        // with empty route and CIDR lists, which general-token validation forbids.
        $this->addSql("DELETE FROM api_token WHERE host_id IS NULL AND name LIKE 'Host token: %' AND JSON_LENGTH(allowed_routes) = 0 AND JSON_LENGTH(allowed_cidrs) = 0");
        $this->addSql('ALTER TABLE api_token DROP FOREIGN KEY FK_7BA2F5EB1FB8D185');
        $this->addSql('ALTER TABLE api_token ADD CONSTRAINT FK_7BA2F5EB1FB8D185 FOREIGN KEY (host_id) REFERENCES host (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_token DROP FOREIGN KEY FK_7BA2F5EB1FB8D185');
        $this->addSql('ALTER TABLE api_token ADD CONSTRAINT FK_7BA2F5EB1FB8D185 FOREIGN KEY (host_id) REFERENCES host (id) ON DELETE SET NULL');
    }
}
