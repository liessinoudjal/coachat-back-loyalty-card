<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds reward type, target value and reward description on contest_reward so
 * merchants can offer card-based prizes (stamp or point loyalty cards) in
 * addition to the existing free-text rewards.
 */
final class Version20260520210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add type, target_value and reward_description columns on contest_reward';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE contest_reward ADD type VARCHAR(32) DEFAULT 'TEXT' NOT NULL");
        $this->addSql('ALTER TABLE contest_reward ADD target_value INT DEFAULT NULL');
        $this->addSql('ALTER TABLE contest_reward ADD reward_description VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contest_reward DROP type');
        $this->addSql('ALTER TABLE contest_reward DROP target_value');
        $this->addSql('ALTER TABLE contest_reward DROP reward_description');
    }
}
