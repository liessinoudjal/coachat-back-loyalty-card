<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260509000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove is_merchant_admin column as merchant admin status is determined by relations and roles';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer DROP is_merchant_admin');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer ADD is_merchant_admin TINYINT(1) DEFAULT 0 NOT NULL');
    }
}
