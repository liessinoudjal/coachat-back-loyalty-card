<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260510000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add geolocation fields (latitude, longitude, geocoded_at, geocode_score) to merchant table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE merchant ADD latitude DOUBLE PRECISION DEFAULT NULL, ADD longitude DOUBLE PRECISION DEFAULT NULL, ADD geocoded_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD geocode_score DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE merchant DROP latitude, DROP longitude, DROP geocoded_at, DROP geocode_score');
    }
}
