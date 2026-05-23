<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260523000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add notification recipient count columns for promotional offers and contests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE promotional_offer ADD start_notification_recipient_count INT DEFAULT NULL, ADD ending_soon_notification_recipient_count INT DEFAULT NULL, ADD day_before_notification_recipient_count INT DEFAULT NULL');
        $this->addSql('ALTER TABLE contest ADD day_before_notification_recipient_count INT DEFAULT NULL, ADD start_notification_recipient_count INT DEFAULT NULL, ADD ending_soon_notification_recipient_count INT DEFAULT NULL, ADD draw_day_notification_recipient_count INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE promotional_offer DROP start_notification_recipient_count, DROP ending_soon_notification_recipient_count, DROP day_before_notification_recipient_count');
        $this->addSql('ALTER TABLE contest DROP day_before_notification_recipient_count, DROP start_notification_recipient_count, DROP ending_soon_notification_recipient_count, DROP draw_day_notification_recipient_count');
    }
}
