<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the "free account" flag on merchants so that the super-admin can
 * grant a perpetual complimentary access (bypasses plan limits and
 * subscription status guards) to partners / beta-testers.
 */
final class Version20260520120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_free_account flag + audit columns on merchant table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE merchant ADD is_free_account TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE merchant ADD free_account_granted_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE merchant ADD free_account_granted_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE merchant ADD CONSTRAINT FK_merchant_free_account_granted_by FOREIGN KEY (free_account_granted_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_merchant_free_account_granted_by ON merchant (free_account_granted_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE merchant DROP FOREIGN KEY FK_merchant_free_account_granted_by');
        $this->addSql('DROP INDEX IDX_merchant_free_account_granted_by ON merchant');
        $this->addSql('ALTER TABLE merchant DROP free_account_granted_by_id');
        $this->addSql('ALTER TABLE merchant DROP free_account_granted_at');
        $this->addSql('ALTER TABLE merchant DROP is_free_account');
    }
}
