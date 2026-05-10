<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260510000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add day_before_notification_sent_at to promotional_offer for flash offer J-1 notifications';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE promotional_offer ADD day_before_notification_sent_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE promotional_offer DROP day_before_notification_sent_at');
    }
}
