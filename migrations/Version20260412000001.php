<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260412000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add phone, address and logo_url nullable columns to merchant table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE merchant ADD phone VARCHAR(50) DEFAULT NULL, ADD address VARCHAR(255) DEFAULT NULL, ADD logo_url LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE merchant DROP phone, DROP address, DROP logo_url');
    }
}
