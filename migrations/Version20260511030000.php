<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create contest_participation and contest_winner tables for merchant contest module';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE IF NOT EXISTS contest_participation (id INT AUTO_INCREMENT NOT NULL, contest_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', customer_id INT NOT NULL, transaction_id INT DEFAULT NULL, is_winning_entry TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_CONTEST_PARTICIPATION_CONTEST_CUSTOMER (contest_id, customer_id), INDEX IDX_CONTEST_PARTICIPATION_WINNING (contest_id, is_winning_entry), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE contest_participation ADD CONSTRAINT FK_CONTEST_PARTICIPATION_CONTEST FOREIGN KEY (contest_id) REFERENCES contest (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE contest_participation ADD CONSTRAINT FK_CONTEST_PARTICIPATION_CUSTOMER FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE contest_participation ADD CONSTRAINT FK_CONTEST_PARTICIPATION_TRANSACTION FOREIGN KEY (transaction_id) REFERENCES transaction (id) ON DELETE SET NULL');

        $this->addSql("CREATE TABLE IF NOT EXISTS contest_winner (id INT AUTO_INCREMENT NOT NULL, contest_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', customer_id INT NOT NULL, reward_id INT NOT NULL, qr_code_token VARCHAR(255) NOT NULL, is_claimed TINYINT(1) NOT NULL DEFAULT 0, claimed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX UNIQ_QR_TOKEN (qr_code_token), INDEX IDX_CONTEST_WINNER_CONTEST (contest_id), INDEX IDX_CONTEST_WINNER_CLAIMED (is_claimed), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE contest_winner ADD CONSTRAINT FK_CONTEST_WINNER_CONTEST FOREIGN KEY (contest_id) REFERENCES contest (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE contest_winner ADD CONSTRAINT FK_CONTEST_WINNER_CUSTOMER FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE contest_winner ADD CONSTRAINT FK_CONTEST_WINNER_REWARD FOREIGN KEY (reward_id) REFERENCES contest_reward (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contest_winner DROP FOREIGN KEY FK_CONTEST_WINNER_CONTEST');
        $this->addSql('ALTER TABLE contest_winner DROP FOREIGN KEY FK_CONTEST_WINNER_CUSTOMER');
        $this->addSql('ALTER TABLE contest_winner DROP FOREIGN KEY FK_CONTEST_WINNER_REWARD');
        $this->addSql('ALTER TABLE contest_participation DROP FOREIGN KEY FK_CONTEST_PARTICIPATION_CONTEST');
        $this->addSql('ALTER TABLE contest_participation DROP FOREIGN KEY FK_CONTEST_PARTICIPATION_CUSTOMER');
        $this->addSql('ALTER TABLE contest_participation DROP FOREIGN KEY FK_CONTEST_PARTICIPATION_TRANSACTION');
        $this->addSql('DROP TABLE IF EXISTS contest_winner');
        $this->addSql('DROP TABLE IF EXISTS contest_participation');
    }
}
