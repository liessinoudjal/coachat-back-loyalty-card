<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260413000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional merchant ownership on customer for direct multi-tenant ownership without loyalty cards';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE customer ADD merchant_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)'");
        $this->addSql('CREATE INDEX IDX_81398E09D124AB56 ON customer (merchant_id)');
        $this->addSql('ALTER TABLE customer ADD CONSTRAINT FK_81398E09D124AB56 FOREIGN KEY (merchant_id) REFERENCES merchant (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer DROP FOREIGN KEY FK_81398E09D124AB56');
        $this->addSql('DROP INDEX IDX_81398E09D124AB56 ON customer');
        $this->addSql('ALTER TABLE customer DROP merchant_id');
    }
}
