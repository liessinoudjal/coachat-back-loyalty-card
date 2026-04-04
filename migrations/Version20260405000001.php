<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260405000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add visible column to loyalty_card table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE loyalty_card ADD visible TINYINT(1) NOT NULL DEFAULT 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE loyalty_card DROP COLUMN visible');
    }
}
