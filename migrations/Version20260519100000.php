<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds email verification fields to the user table and activates every
 * pre-existing account so that the new verification gate does not break
 * existing customers and merchants.
 */
final class Version20260519100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add email verification fields on user and activate legacy accounts by default';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD email_verified TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE `user` ADD email_verification_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE `user` ADD email_verification_token_sent_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE `user` ADD email_verification_sent_to VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE `user` ADD email_verified_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');

        // Legacy accounts are considered verified so that no current user is
        // locked out by the new verification flow. Newly created accounts
        // will default to email_verified = 0 because the column default at
        // INSERT time is set by Doctrine through the entity, not this default.
        $this->addSql('UPDATE `user` SET email_verified = 1, email_verified_at = NOW() WHERE email_verified = 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP email_verified');
        $this->addSql('ALTER TABLE `user` DROP email_verification_token');
        $this->addSql('ALTER TABLE `user` DROP email_verification_token_sent_at');
        $this->addSql('ALTER TABLE `user` DROP email_verification_sent_to');
        $this->addSql('ALTER TABLE `user` DROP email_verified_at');
    }
}
