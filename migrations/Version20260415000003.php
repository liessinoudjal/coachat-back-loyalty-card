<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260415000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add legal terms acceptance proof fields to merchant';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE merchant ADD accepted_terms TINYINT(1) NOT NULL DEFAULT 0, ADD accepted_terms_version VARCHAR(32) DEFAULT NULL, ADD accepted_terms_accepted_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_74AB25E17EF0E24A ON merchant (accepted_terms)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_74AB25E17EF0E24A ON merchant');
        $this->addSql('ALTER TABLE merchant DROP accepted_terms, DROP accepted_terms_version, DROP accepted_terms_accepted_at');
    }
}
