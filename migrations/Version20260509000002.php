<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260509000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_merchant_admin column to customer table for merchant admin permissions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer ADD is_merchant_admin TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer DROP is_merchant_admin');
    }
}
