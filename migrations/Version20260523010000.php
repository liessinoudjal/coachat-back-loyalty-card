<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260523010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional Instagram, TikTok and website URLs on merchant profile';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE merchant ADD instagram_url VARCHAR(255) DEFAULT NULL, ADD tiktok_url VARCHAR(255) DEFAULT NULL, ADD website_url VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE merchant DROP instagram_url, DROP tiktok_url, DROP website_url');
    }
}
