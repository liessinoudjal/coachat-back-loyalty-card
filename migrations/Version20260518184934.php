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
        $table = $schema->getTable('loyalty_program');
        $table->addColumn('card_background_image_url', 'text', ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('loyalty_program');
        $table->dropColumn('card_background_image_url');
    }
}
