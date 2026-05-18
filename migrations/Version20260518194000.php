<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260518194000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow merchants without owner user (user_id nullable)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE merchant CHANGE user_id user_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE merchant CHANGE user_id user_id INT NOT NULL');
    }
}
