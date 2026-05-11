<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create contest and contest_reward tables for merchant contest module';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE contest (id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', merchant_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, start_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', end_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', draw_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_CONTEST_MERCHANT_STATUS (merchant_id, status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE contest ADD CONSTRAINT FK_CONTEST_MERCHANT FOREIGN KEY (merchant_id) REFERENCES merchant (id) ON DELETE CASCADE');

        $this->addSql("CREATE TABLE contest_reward (id INT AUTO_INCREMENT NOT NULL, contest_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', title VARCHAR(255) NOT NULL, image_url VARCHAR(2048) DEFAULT NULL, `rank` INT NOT NULL, INDEX IDX_CONTEST_REWARD_CONTEST (contest_id), UNIQUE INDEX UNIQ_CONTEST_REWARD_RANK (contest_id, `rank`), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE contest_reward ADD CONSTRAINT FK_CONTEST_REWARD_CONTEST FOREIGN KEY (contest_id) REFERENCES contest (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contest_reward DROP FOREIGN KEY FK_CONTEST_REWARD_CONTEST');
        $this->addSql('ALTER TABLE contest DROP FOREIGN KEY FK_CONTEST_MERCHANT');
        $this->addSql('DROP TABLE contest_reward');
        $this->addSql('DROP TABLE contest');
    }
}
