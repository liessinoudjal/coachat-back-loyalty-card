<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260404000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop qr_code column from loyalty_card table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE loyalty_card DROP INDEX UNIQ_EAB8BCBD7D8B1FB5');
        $this->addSql('ALTER TABLE loyalty_card DROP COLUMN qr_code');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE loyalty_card ADD qr_code VARCHAR(255) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EAB8BCBD7D8B1FB5 ON loyalty_card (qr_code)');
    }
}
