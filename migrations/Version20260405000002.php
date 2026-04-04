<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260405000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create device_registration table for Apple PassKit Web Service';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE device_registration (
            id INT AUTO_INCREMENT NOT NULL,
            device_library_identifier VARCHAR(255) NOT NULL,
            push_token VARCHAR(255) NOT NULL,
            pass_type_identifier VARCHAR(255) NOT NULL,
            serial_number VARCHAR(36) NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX device_serial_unique (device_library_identifier, serial_number),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE device_registration');
    }
}
