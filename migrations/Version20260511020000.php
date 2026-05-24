<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create contest_participation and contest_winner tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE contest_participation (
                id INT AUTO_INCREMENT PRIMARY KEY,
                contest_id BINARY(16) NOT NULL,
                customer_id INT NOT NULL,
                transaction_id INT,
                is_winning_entry TINYINT(1) DEFAULT 0 NOT NULL,
                created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)",
                INDEX IDX_CONTEST_PARTICIPATION_CONTEST_CUSTOMER (contest_id, customer_id),
                INDEX IDX_CONTEST_PARTICIPATION_WINNING_ENTRY (contest_id, is_winning_entry),
                CONSTRAINT FK_CONTEST_PARTICIPATION_CONTEST FOREIGN KEY (contest_id) REFERENCES contest(id) ON DELETE CASCADE,
                CONSTRAINT FK_CONTEST_PARTICIPATION_CUSTOMER FOREIGN KEY (customer_id) REFERENCES customer(id) ON DELETE CASCADE,
                CONSTRAINT FK_CONTEST_PARTICIPATION_TRANSACTION FOREIGN KEY (transaction_id) REFERENCES transaction(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->addSql('
            CREATE TABLE contest_winner (
                id INT AUTO_INCREMENT PRIMARY KEY,
                contest_id BINARY(16) NOT NULL,
                customer_id INT NOT NULL,
                reward_id INT NOT NULL,
                qr_code_token VARCHAR(255) NOT NULL UNIQUE,
                is_claimed TINYINT(1) DEFAULT 0 NOT NULL,
                claimed_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)",
                created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)",
                INDEX IDX_CONTEST_WINNER_QR_TOKEN (qr_code_token),
                INDEX IDX_CONTEST_WINNER_CONTEST_CUSTOMER (contest_id, customer_id),
                INDEX IDX_CONTEST_WINNER_CLAIMED (is_claimed),
                CONSTRAINT FK_CONTEST_WINNER_CONTEST FOREIGN KEY (contest_id) REFERENCES contest(id) ON DELETE CASCADE,
                CONSTRAINT FK_CONTEST_WINNER_CUSTOMER FOREIGN KEY (customer_id) REFERENCES customer(id) ON DELETE CASCADE,
                CONSTRAINT FK_CONTEST_WINNER_REWARD FOREIGN KEY (reward_id) REFERENCES contest_reward(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE contest_winner');
        $this->addSql('DROP TABLE contest_participation');
    }
}
