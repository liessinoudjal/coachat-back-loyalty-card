<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260518184934 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add card_background_image_url column to loyalty_program table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE loyalty_program ADD COLUMN card_background_image_url LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE loyalty_program DROP COLUMN card_background_image_url');
    }
}
