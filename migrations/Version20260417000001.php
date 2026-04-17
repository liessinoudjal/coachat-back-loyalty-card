<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260417000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create notification_log audit table for customer notification delivery';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE notification_log (id VARCHAR(36) NOT NULL, recipient_email VARCHAR(255) NOT NULL, type VARCHAR(50) NOT NULL, subject VARCHAR(255) DEFAULT NULL, status VARCHAR(20) NOT NULL, error_message LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, sent_at DATETIME DEFAULT NULL, merchant_id BINARY(16) NOT NULL, INDEX IDX_ED15DF26796D554 (merchant_id), INDEX IDX_NOTIFICATION_LOG_STATUS_CREATED (status, created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE notification_log ADD CONSTRAINT FK_ED15DF26796D554 FOREIGN KEY (merchant_id) REFERENCES merchant (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification_log DROP FOREIGN KEY FK_ED15DF26796D554');
        $this->addSql('DROP TABLE notification_log');
    }
}