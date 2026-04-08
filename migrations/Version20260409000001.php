<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260409000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create customer_portal_session table for temporary public customer access';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE customer_portal_session (
            id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
            customer_id INT NOT NULL,
            token_hash VARCHAR(64) NOT NULL,
            issued_from_wallet_token VARCHAR(36) DEFAULT NULL,
            issued_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            original_issued_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            expires_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            revoked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            last_used_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            ip VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(512) DEFAULT NULL,
            scope VARCHAR(255) NOT NULL DEFAULT 'cards:read rewards:read',
            INDEX IDX_PORTAL_CUSTOMER (customer_id),
            INDEX IDX_PORTAL_EXPIRES_AT (expires_at),
            UNIQUE INDEX UNIQ_PORTAL_TOKEN_HASH (token_hash),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql('ALTER TABLE customer_portal_session ADD CONSTRAINT FK_PORTAL_SESSION_CUSTOMER FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_portal_session DROP FOREIGN KEY FK_PORTAL_SESSION_CUSTOMER');
        $this->addSql('DROP TABLE customer_portal_session');
    }
}
