<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260428000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add equipier/staff assignment fields on customer';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE customer ADD staff_merchant_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)', ADD staff_assigned_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql('CREATE INDEX IDX_81398E0979AA2BE6 ON customer (staff_merchant_id)');
        $this->addSql('ALTER TABLE customer ADD CONSTRAINT FK_81398E0979AA2BE6 FOREIGN KEY (staff_merchant_id) REFERENCES merchant (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer DROP FOREIGN KEY FK_81398E0979AA2BE6');
        $this->addSql('DROP INDEX IDX_81398E0979AA2BE6 ON customer');
        $this->addSql('ALTER TABLE customer DROP staff_merchant_id, DROP staff_assigned_at');
    }
}
