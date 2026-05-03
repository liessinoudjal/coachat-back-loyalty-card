<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260503000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add created_at to customer table and backfill existing customers';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer ADD created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL');
        $this->addSql('UPDATE customer SET created_at = NOW() WHERE created_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer DROP created_at');
    }
}