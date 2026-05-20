<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the contest_reward_card table that stores scannable loyalty-like cards
 * issued to contest winners when the won reward is of type CARD_STAMP or
 * CARD_POINT. Each card is unique per contest winner and has its own wallet
 * token used to generate the customer-facing QR code.
 */
final class Version20260520213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create contest_reward_card table for scannable contest reward cards';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE contest_reward_card ("
            . "id INT AUTO_INCREMENT NOT NULL, "
            . "contest_winner_id INT NOT NULL, "
            . "customer_id INT NOT NULL, "
            . "merchant_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', "
            . "wallet_token VARCHAR(36) NOT NULL, "
            . "type VARCHAR(32) NOT NULL, "
            . "title VARCHAR(255) NOT NULL, "
            . "reward_description VARCHAR(255) DEFAULT NULL, "
            . "target_value INT NOT NULL, "
            . "current_value INT NOT NULL, "
            . "is_completed TINYINT(1) NOT NULL, "
            . "completed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', "
            . "created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', "
            . "UNIQUE INDEX UNIQ_CRC_WALLET (wallet_token), "
            . "UNIQUE INDEX UNIQ_CRC_WINNER (contest_winner_id), "
            . "INDEX IDX_CRC_CUSTOMER (customer_id), "
            . "INDEX IDX_CRC_MERCHANT (merchant_id), "
            . "PRIMARY KEY(id)"
            . ") DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql('ALTER TABLE contest_reward_card ADD CONSTRAINT FK_CRC_WINNER FOREIGN KEY (contest_winner_id) REFERENCES contest_winner (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE contest_reward_card ADD CONSTRAINT FK_CRC_CUSTOMER FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE contest_reward_card ADD CONSTRAINT FK_CRC_MERCHANT FOREIGN KEY (merchant_id) REFERENCES merchant (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contest_reward_card DROP FOREIGN KEY FK_CRC_WINNER');
        $this->addSql('ALTER TABLE contest_reward_card DROP FOREIGN KEY FK_CRC_CUSTOMER');
        $this->addSql('ALTER TABLE contest_reward_card DROP FOREIGN KEY FK_CRC_MERCHANT');
        $this->addSql('DROP TABLE contest_reward_card');
    }
}
