<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260509000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create promotional_offer table and add promotional notification preference';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE promotional_offer (id INT AUTO_INCREMENT NOT NULL, merchant_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', title VARCHAR(160) NOT NULL, description LONGTEXT NOT NULL, starts_on DATE NOT NULL COMMENT '(DC2Type:date_immutable)', ends_on DATE NOT NULL COMMENT '(DC2Type:date_immutable)', start_notification_sent_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', ending_soon_notification_sent_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_PROMOTIONAL_OFFER_MERCHANT_STARTS_ON (merchant_id, starts_on), INDEX IDX_PROMOTIONAL_OFFER_MERCHANT_ENDS_ON (merchant_id, ends_on), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE promotional_offer ADD CONSTRAINT FK_4B213D9A4DE7DC5C FOREIGN KEY (merchant_id) REFERENCES merchant (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE customer_merchant_notification_preference ADD promotional_offers_enabled TINYINT(1) DEFAULT 1 NOT NULL');
        $this->addSql('UPDATE customer_merchant_notification_preference SET promotional_offers_enabled = 1 WHERE promotional_offers_enabled IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE promotional_offer DROP FOREIGN KEY FK_4B213D9A4DE7DC5C');
        $this->addSql('DROP TABLE promotional_offer');

        $this->addSql('ALTER TABLE customer_merchant_notification_preference DROP promotional_offers_enabled');
    }
}