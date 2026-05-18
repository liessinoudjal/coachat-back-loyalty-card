<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260518195500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make merchant.email nullable for unclaimed merchants flow';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE merchant CHANGE email email VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE merchant SET email = CONCAT(LOWER(REPLACE(company_name, " ", "")), ".placeholder@coachat.local") WHERE email IS NULL');
        $this->addSql('ALTER TABLE merchant CHANGE email email VARCHAR(180) NOT NULL');
    }
}
