<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add draw_day_notification_sent_at to contest for draw day notifications';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE contest ADD draw_day_notification_sent_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contest DROP draw_day_notification_sent_at');
    }
}
