<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260526000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create campaign_qr_code and campaign_qr_scan_event tables for trackable printable QR campaigns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS campaign_qr_code (
            id INT AUTO_INCREMENT NOT NULL,
            slug VARCHAR(32) NOT NULL,
            name VARCHAR(120) NOT NULL,
            template_key VARCHAR(40) NOT NULL,
            target_path VARCHAR(255) NOT NULL,
            utm_source VARCHAR(80) NOT NULL,
            utm_medium VARCHAR(80) NOT NULL,
            utm_campaign VARCHAR(80) NOT NULL,
            scan_count INT DEFAULT 0 NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            archived_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_CAMPAIGN_QR_CODE_SLUG (slug),
            INDEX IDX_CAMPAIGN_QR_SLUG (slug),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE IF NOT EXISTS campaign_qr_scan_event (
            id INT AUTO_INCREMENT NOT NULL,
            campaign_qr_code_id INT NOT NULL,
            occurred_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            ip_hash VARCHAR(64) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            referer VARCHAR(255) DEFAULT NULL,
            INDEX IDX_QR_SCAN_CAMPAIGN_OCCURRED (campaign_qr_code_id, occurred_at),
            INDEX IDX_QR_SCAN_CAMPAIGN (campaign_qr_code_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE campaign_qr_scan_event ADD CONSTRAINT FK_QR_SCAN_CAMPAIGN FOREIGN KEY (campaign_qr_code_id) REFERENCES campaign_qr_code (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE campaign_qr_scan_event DROP FOREIGN KEY FK_QR_SCAN_CAMPAIGN');
        $this->addSql('DROP TABLE IF EXISTS campaign_qr_scan_event');
        $this->addSql('DROP TABLE IF EXISTS campaign_qr_code');
    }
}
