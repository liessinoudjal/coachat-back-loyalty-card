<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260503000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add accepted_terms fields to customer table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer ADD accepted_terms TINYINT(1) NOT NULL DEFAULT 0, ADD accepted_terms_version VARCHAR(32) DEFAULT NULL, ADD accepted_terms_accepted_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer DROP accepted_terms, DROP accepted_terms_version, DROP accepted_terms_accepted_at');
    }
}
