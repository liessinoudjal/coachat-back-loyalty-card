<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260415000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add persisted Stripe current period dates on merchant for webhook backfill';
    }

    public function up(Schema $schema): void
    {
        // Nullable to keep backward compatibility and allow progressive webhook backfill for existing merchants.
        $this->addSql('ALTER TABLE merchant ADD current_period_start_at DATETIME DEFAULT NULL, ADD current_period_end_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE merchant DROP current_period_start_at, DROP current_period_end_at');
    }
}
