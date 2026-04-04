<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260404000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add wallet_token column to loyalty_card table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE loyalty_card ADD wallet_token VARCHAR(36) NOT NULL DEFAULT ''");
        $this->addSql('UPDATE loyalty_card SET wallet_token = UUID() WHERE wallet_token = \'\'');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EAB8BCBDF3ED4EE ON loyalty_card (wallet_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_EAB8BCBDF3ED4EE ON loyalty_card');
        $this->addSql('ALTER TABLE loyalty_card DROP wallet_token');
    }
}
