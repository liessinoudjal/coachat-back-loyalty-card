<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260408000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create reward and reward_status_log tables with indexes and constraints';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE reward (
            id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
            loyalty_card_id INT NOT NULL,
            merchant_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
            customer_id INT NOT NULL,
            loyalty_program_id INT DEFAULT NULL,
            claimed_by_merchant_user_id INT DEFAULT NULL,
            reward_description VARCHAR(255) DEFAULT NULL,
            status VARCHAR(20) NOT NULL,
            claim_qr_token VARCHAR(128) NOT NULL,
            generated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            claimed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            cancel_reason LONGTEXT DEFAULT NULL,
            metadata JSON DEFAULT NULL,
            INDEX IDX_REWARD_MERCHANT (merchant_id),
            INDEX IDX_REWARD_CUSTOMER (customer_id),
            INDEX IDX_REWARD_STATUS (status),
            INDEX IDX_REWARD_CLAIM_TOKEN (claim_qr_token),
            INDEX IDX_REWARD_GENERATED_AT (generated_at),
            INDEX IDX_REWARD_LOYALTY_PROGRAM (loyalty_program_id),
            INDEX IDX_REWARD_CLAIMED_BY_USER (claimed_by_merchant_user_id),
            UNIQUE INDEX UNIQ_REWARD_CLAIM_QR_TOKEN (claim_qr_token),
            UNIQUE INDEX UNIQ_REWARD_LOYALTY_CARD (loyalty_card_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql("CREATE TABLE reward_status_log (
            id INT AUTO_INCREMENT NOT NULL,
            reward_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
            actor_merchant_user_id INT DEFAULT NULL,
            from_status VARCHAR(20) DEFAULT NULL,
            to_status VARCHAR(20) NOT NULL,
            changed_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            reason LONGTEXT DEFAULT NULL,
            metadata JSON DEFAULT NULL,
            INDEX IDX_REWARD_STATUS_LOG_REWARD_DATE (reward_id, changed_at),
            INDEX IDX_REWARD_STATUS_LOG_ACTOR (actor_merchant_user_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql('ALTER TABLE reward ADD CONSTRAINT FK_REWARD_LOYALTY_CARD FOREIGN KEY (loyalty_card_id) REFERENCES loyalty_card (id)');
        $this->addSql('ALTER TABLE reward ADD CONSTRAINT FK_REWARD_MERCHANT FOREIGN KEY (merchant_id) REFERENCES merchant (id)');
        $this->addSql('ALTER TABLE reward ADD CONSTRAINT FK_REWARD_CUSTOMER FOREIGN KEY (customer_id) REFERENCES customer (id)');
        $this->addSql('ALTER TABLE reward ADD CONSTRAINT FK_REWARD_LOYALTY_PROGRAM FOREIGN KEY (loyalty_program_id) REFERENCES loyalty_program (id)');
        $this->addSql('ALTER TABLE reward ADD CONSTRAINT FK_REWARD_CLAIMED_BY_USER FOREIGN KEY (claimed_by_merchant_user_id) REFERENCES `user` (id)');

        $this->addSql('ALTER TABLE reward_status_log ADD CONSTRAINT FK_REWARD_STATUS_LOG_REWARD FOREIGN KEY (reward_id) REFERENCES reward (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reward_status_log ADD CONSTRAINT FK_REWARD_STATUS_LOG_ACTOR FOREIGN KEY (actor_merchant_user_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reward_status_log DROP FOREIGN KEY FK_REWARD_STATUS_LOG_REWARD');
        $this->addSql('ALTER TABLE reward_status_log DROP FOREIGN KEY FK_REWARD_STATUS_LOG_ACTOR');

        $this->addSql('ALTER TABLE reward DROP FOREIGN KEY FK_REWARD_LOYALTY_CARD');
        $this->addSql('ALTER TABLE reward DROP FOREIGN KEY FK_REWARD_MERCHANT');
        $this->addSql('ALTER TABLE reward DROP FOREIGN KEY FK_REWARD_CUSTOMER');
        $this->addSql('ALTER TABLE reward DROP FOREIGN KEY FK_REWARD_LOYALTY_PROGRAM');
        $this->addSql('ALTER TABLE reward DROP FOREIGN KEY FK_REWARD_CLAIMED_BY_USER');

        $this->addSql('DROP TABLE reward_status_log');
        $this->addSql('DROP TABLE reward');
    }
}
