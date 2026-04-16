<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260416000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create customer per merchant notification preferences with default enabled backfill';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE customer_merchant_notification_preference (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, merchant_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', enabled TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_7E7330D39395C3F3 (customer_id), INDEX IDX_7E7330D36796D554 (merchant_id), UNIQUE INDEX uniq_customer_merchant_notification_pref (customer_id, merchant_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE customer_merchant_notification_preference ADD CONSTRAINT FK_7E7330D39395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_merchant_notification_preference ADD CONSTRAINT FK_7E7330D36796D554 FOREIGN KEY (merchant_id) REFERENCES merchant (id) ON DELETE CASCADE');
        $this->addSql("INSERT INTO customer_merchant_notification_preference (customer_id, merchant_id, enabled, created_at, updated_at)
            SELECT DISTINCT cm.customer_id, cm.merchant_id, 1, NOW(), NOW()
            FROM customer_merchants cm
            LEFT JOIN customer_merchant_notification_preference pref
                ON pref.customer_id = cm.customer_id AND pref.merchant_id = cm.merchant_id
            WHERE pref.id IS NULL");
        $this->addSql("INSERT INTO customer_merchant_notification_preference (customer_id, merchant_id, enabled, created_at, updated_at)
            SELECT DISTINCT c.id, c.merchant_id, 1, NOW(), NOW()
            FROM customer c
            LEFT JOIN customer_merchant_notification_preference pref
                ON pref.customer_id = c.id AND pref.merchant_id = c.merchant_id
            WHERE c.merchant_id IS NOT NULL AND pref.id IS NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_merchant_notification_preference DROP FOREIGN KEY FK_7E7330D39395C3F3');
        $this->addSql('ALTER TABLE customer_merchant_notification_preference DROP FOREIGN KEY FK_7E7330D36796D554');
        $this->addSql('DROP TABLE customer_merchant_notification_preference');
    }
}