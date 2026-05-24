<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260523011000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional establishment_type on merchant';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE merchant ADD establishment_type VARCHAR(32) DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE merchant DROP establishment_type');
    }
}
