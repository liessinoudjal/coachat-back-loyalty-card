<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260415000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional postal_code and city fields to merchant';
    }

    public function up(Schema $schema): void
    {
        // These columns stay nullable in database for existing merchants, but are required by the API for new merchants.
        $this->addSql('ALTER TABLE merchant ADD postal_code VARCHAR(10) DEFAULT NULL, ADD city VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE merchant DROP postal_code, DROP city');
    }
}
