<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260509000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add created_at to loyalty_card and create merchant_asset_download_event table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE loyalty_card ADD created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '(DC2Type:datetime_immutable)'");

        $this->addSql("CREATE TABLE merchant_asset_download_event (id INT AUTO_INCREMENT NOT NULL, merchant_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', event_type VARCHAR(20) NOT NULL, asset_type VARCHAR(50) NOT NULL, occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_ASSET_DL_MERCHANT_OCCURRED (merchant_id, occurred_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE merchant_asset_download_event ADD CONSTRAINT FK_ASSET_DL_MERCHANT FOREIGN KEY (merchant_id) REFERENCES merchant (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE merchant_asset_download_event DROP FOREIGN KEY FK_ASSET_DL_MERCHANT');
        $this->addSql('DROP TABLE merchant_asset_download_event');

        $this->addSql('ALTER TABLE loyalty_card DROP created_at');
    }
}
