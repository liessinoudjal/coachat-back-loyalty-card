<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260419000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Google review module, session, reward and event tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE merchant_google_review_module (id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', merchant_id BINARY(16) NOT NULL, is_enabled TINYINT(1) NOT NULL DEFAULT 0, display_name VARCHAR(255) NOT NULL DEFAULT 'Avis Google', google_review_url LONGTEXT DEFAULT NULL, google_place_id VARCHAR(255) DEFAULT NULL, google_place_name VARCHAR(255) DEFAULT NULL, show_in_customer_dashboard TINYINT(1) NOT NULL DEFAULT 1, show_qr_code TINYINT(1) NOT NULL DEFAULT 1, reward_options JSON NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX UNIQ_GOOGLE_REVIEW_MODULE_MERCHANT (merchant_id), INDEX IDX_GOOGLE_REVIEW_MODULE_ENABLED (is_enabled), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE google_review_session (id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', merchant_id BINARY(16) NOT NULL, customer_id INT NOT NULL, module_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', status VARCHAR(32) NOT NULL, launch_count INT NOT NULL DEFAULT 0, launched_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', returned_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', spun_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_GOOGLE_REVIEW_SESSION_CUSTOMER_MERCHANT (customer_id, merchant_id), INDEX IDX_GOOGLE_REVIEW_SESSION_STATUS (status), INDEX IDX_GOOGLE_REVIEW_SESSION_CREATED_AT (created_at), INDEX IDX_968499D62796D554 (merchant_id), INDEX IDX_968499D69395C3F3 (module_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE google_review_reward (id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', session_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', merchant_id BINARY(16) NOT NULL, customer_id INT NOT NULL, redeemed_by_id INT DEFAULT NULL, reward_label VARCHAR(255) NOT NULL, reward_description LONGTEXT DEFAULT NULL, qr_token VARCHAR(128) NOT NULL, qr_payload LONGTEXT DEFAULT NULL, status VARCHAR(32) NOT NULL, redeemed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX UNIQ_GOOGLE_REVIEW_REWARD_SESSION (session_id), UNIQUE INDEX UNIQ_GOOGLE_REVIEW_REWARD_QR_TOKEN (qr_token), INDEX IDX_GOOGLE_REVIEW_REWARD_CUSTOMER_MERCHANT (customer_id, merchant_id), INDEX IDX_GOOGLE_REVIEW_REWARD_STATUS (status), INDEX IDX_9E3D5D22796D554 (merchant_id), INDEX IDX_9E3D5D22B03A8386 (redeemed_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE google_review_event (id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', merchant_id BINARY(16) NOT NULL, customer_id INT DEFAULT NULL, module_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', session_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)', event_type VARCHAR(32) NOT NULL, source VARCHAR(64) NOT NULL, metadata JSON DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_GOOGLE_REVIEW_EVENT_MERCHANT_DATE (merchant_id, created_at), INDEX IDX_GOOGLE_REVIEW_EVENT_SESSION (session_id), INDEX IDX_GOOGLE_REVIEW_EVENT_TYPE (event_type), INDEX IDX_6611E6E2796D554 (merchant_id), INDEX IDX_6611E6E29395C3F3 (module_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE merchant_google_review_module ADD CONSTRAINT FK_9EEB8D83796D554 FOREIGN KEY (merchant_id) REFERENCES merchant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE google_review_session ADD CONSTRAINT FK_968499D62796D554 FOREIGN KEY (merchant_id) REFERENCES merchant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE google_review_session ADD CONSTRAINT FK_968499D664B6CC53 FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE google_review_session ADD CONSTRAINT FK_968499D69395C3F3 FOREIGN KEY (module_id) REFERENCES merchant_google_review_module (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE google_review_reward ADD CONSTRAINT FK_9E3D5D22D1F1D193 FOREIGN KEY (session_id) REFERENCES google_review_session (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE google_review_reward ADD CONSTRAINT FK_9E3D5D22796D554 FOREIGN KEY (merchant_id) REFERENCES merchant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE google_review_reward ADD CONSTRAINT FK_9E3D5D2264B6CC53 FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE google_review_reward ADD CONSTRAINT FK_9E3D5D22B03A8386 FOREIGN KEY (redeemed_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE google_review_event ADD CONSTRAINT FK_6611E6E2796D554 FOREIGN KEY (merchant_id) REFERENCES merchant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE google_review_event ADD CONSTRAINT FK_6611E6E264B6CC53 FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE google_review_event ADD CONSTRAINT FK_6611E6E29395C3F3 FOREIGN KEY (module_id) REFERENCES merchant_google_review_module (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE google_review_event ADD CONSTRAINT FK_6611E6E2613FECDF FOREIGN KEY (session_id) REFERENCES google_review_session (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE google_review_event DROP FOREIGN KEY FK_6611E6E2796D554');
        $this->addSql('ALTER TABLE google_review_event DROP FOREIGN KEY FK_6611E6E264B6CC53');
        $this->addSql('ALTER TABLE google_review_event DROP FOREIGN KEY FK_6611E6E29395C3F3');
        $this->addSql('ALTER TABLE google_review_event DROP FOREIGN KEY FK_6611E6E2613FECDF');
        $this->addSql('ALTER TABLE google_review_reward DROP FOREIGN KEY FK_9E3D5D22D1F1D193');
        $this->addSql('ALTER TABLE google_review_reward DROP FOREIGN KEY FK_9E3D5D22796D554');
        $this->addSql('ALTER TABLE google_review_reward DROP FOREIGN KEY FK_9E3D5D2264B6CC53');
        $this->addSql('ALTER TABLE google_review_reward DROP FOREIGN KEY FK_9E3D5D22B03A8386');
        $this->addSql('ALTER TABLE google_review_session DROP FOREIGN KEY FK_968499D62796D554');
        $this->addSql('ALTER TABLE google_review_session DROP FOREIGN KEY FK_968499D664B6CC53');
        $this->addSql('ALTER TABLE google_review_session DROP FOREIGN KEY FK_968499D69395C3F3');
        $this->addSql('ALTER TABLE merchant_google_review_module DROP FOREIGN KEY FK_9EEB8D83796D554');
        $this->addSql('DROP TABLE google_review_event');
        $this->addSql('DROP TABLE google_review_reward');
        $this->addSql('DROP TABLE google_review_session');
        $this->addSql('DROP TABLE merchant_google_review_module');
    }
}