<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add contest notification tracking timestamps and customer contest notification preference';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE contest ADD start_notification_sent_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', ADD ending_soon_notification_sent_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', ADD day_before_notification_sent_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql('ALTER TABLE customer_merchant_notification_preference ADD contest_notifications_enabled TINYINT(1) DEFAULT 1 NOT NULL');
        $this->addSql('UPDATE customer_merchant_notification_preference SET contest_notifications_enabled = 1 WHERE contest_notifications_enabled IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_merchant_notification_preference DROP contest_notifications_enabled');
        $this->addSql('ALTER TABLE contest DROP start_notification_sent_at, DROP ending_soon_notification_sent_at, DROP day_before_notification_sent_at');
    }
}
