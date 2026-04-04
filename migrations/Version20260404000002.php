<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260404000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create plan table, add plan_id to merchant, seed default plans';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE plan (
            id VARCHAR(36) NOT NULL,
            slug VARCHAR(50) NOT NULL,
            name VARCHAR(100) NOT NULL,
            price_monthly INT NOT NULL,
            max_customers INT NOT NULL,
            max_programs INT NOT NULL,
            has_wallet_integration TINYINT(1) NOT NULL,
            has_push_notifications TINYINT(1) NOT NULL,
            has_advanced_stats TINYINT(1) NOT NULL,
            is_active TINYINT(1) NOT NULL,
            stripe_price_id VARCHAR(255) DEFAULT NULL,
            UNIQUE INDEX UNIQ_PLAN_SLUG (slug),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE merchant ADD plan_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE merchant ADD CONSTRAINT FK_74AB25E1E899029B FOREIGN KEY (plan_id) REFERENCES plan (id)');
        $this->addSql('CREATE INDEX IDX_74AB25E1E899029B ON merchant (plan_id)');

        // Seed the 3 default plans
        $this->addSql("INSERT INTO plan (id, slug, name, price_monthly, max_customers, max_programs, has_wallet_integration, has_push_notifications, has_advanced_stats, is_active, stripe_price_id)
            VALUES
            (UUID(), 'free',     'Gratuit',  0,    50,  1,  0, 0, 0, 1, NULL),
            (UUID(), 'standard', 'Standard', 1900, 500, 5,  0, 0, 0, 1, NULL),
            (UUID(), 'premium',  'Premium',  2900, -1,  -1, 1, 1, 1, 1, NULL)
        ");

        // Assign free plan to all existing merchants that have no plan
        $this->addSql("UPDATE merchant SET plan_id = (SELECT id FROM plan WHERE slug = 'free') WHERE plan_id IS NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE merchant DROP FOREIGN KEY FK_74AB25E1E899029B');
        $this->addSql('DROP INDEX IDX_74AB25E1E899029B ON merchant');
        $this->addSql('ALTER TABLE merchant DROP COLUMN plan_id');
        $this->addSql('DROP TABLE plan');
    }
}
