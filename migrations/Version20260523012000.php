<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260523012000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalize merchant establishment type into reference table with seeded values';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS merchant_establishment_type (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(64) NOT NULL, label VARCHAR(255) NOT NULL, UNIQUE INDEX UNIQ_MERCHANT_ESTABLISHMENT_TYPE_CODE (code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql("INSERT INTO merchant_establishment_type (code, label) VALUES ('restaurant', 'Restaurant') ON DUPLICATE KEY UPDATE label = VALUES(label)");
        $this->addSql("INSERT INTO merchant_establishment_type (code, label) VALUES ('restaurant_asiatique', 'Restaurant asiatique') ON DUPLICATE KEY UPDATE label = VALUES(label)");
        $this->addSql("INSERT INTO merchant_establishment_type (code, label) VALUES ('restaurant_burger', 'Restaurant burger') ON DUPLICATE KEY UPDATE label = VALUES(label)");
        $this->addSql("INSERT INTO merchant_establishment_type (code, label) VALUES ('restaurant_chicken', 'Restaurant chicken') ON DUPLICATE KEY UPDATE label = VALUES(label)");
        $this->addSql("INSERT INTO merchant_establishment_type (code, label) VALUES ('restaurant_kebab', 'Restaurant kebab') ON DUPLICATE KEY UPDATE label = VALUES(label)");
        $this->addSql("INSERT INTO merchant_establishment_type (code, label) VALUES ('fast_food', 'Fast food') ON DUPLICATE KEY UPDATE label = VALUES(label)");
        $this->addSql("INSERT INTO merchant_establishment_type (code, label) VALUES ('brasserie', 'Brasserie') ON DUPLICATE KEY UPDATE label = VALUES(label)");
        $this->addSql("INSERT INTO merchant_establishment_type (code, label) VALUES ('pizzeria', 'Pizzeria') ON DUPLICATE KEY UPDATE label = VALUES(label)");
        $this->addSql("INSERT INTO merchant_establishment_type (code, label) VALUES ('grill', 'Grill') ON DUPLICATE KEY UPDATE label = VALUES(label)");
        $this->addSql("INSERT INTO merchant_establishment_type (code, label) VALUES ('creperie', 'Crêperie') ON DUPLICATE KEY UPDATE label = VALUES(label)");
        $this->addSql("INSERT INTO merchant_establishment_type (code, label) VALUES ('sandwicherie', 'Sandwicherie') ON DUPLICATE KEY UPDATE label = VALUES(label)");
        $this->addSql("INSERT INTO merchant_establishment_type (code, label) VALUES ('cafe', 'Café') ON DUPLICATE KEY UPDATE label = VALUES(label)");
        $this->addSql("INSERT INTO merchant_establishment_type (code, label) VALUES ('bar', 'Bar / café') ON DUPLICATE KEY UPDATE label = VALUES(label)");
        $this->addSql("INSERT INTO merchant_establishment_type (code, label) VALUES ('food_truck', 'Food truck') ON DUPLICATE KEY UPDATE label = VALUES(label)");
        $this->addSql("INSERT INTO merchant_establishment_type (code, label) VALUES ('metier_de_bouche', 'Metier de bouche') ON DUPLICATE KEY UPDATE label = VALUES(label)");
        $this->addSql("INSERT INTO merchant_establishment_type (code, label) VALUES ('boulangerie', 'Boulangerie') ON DUPLICATE KEY UPDATE label = VALUES(label)");
        $this->addSql("INSERT INTO merchant_establishment_type (code, label) VALUES ('boucherie', 'Boucherie / Charcuterie') ON DUPLICATE KEY UPDATE label = VALUES(label)");
        $this->addSql("INSERT INTO merchant_establishment_type (code, label) VALUES ('boucherie_halal', 'Boucherie halal') ON DUPLICATE KEY UPDATE label = VALUES(label)");
        $this->addSql("INSERT INTO merchant_establishment_type (code, label) VALUES ('boucherie_charcuterie_halal', 'Boucherie / charcuterie halal') ON DUPLICATE KEY UPDATE label = VALUES(label)");
        $this->addSql("INSERT INTO merchant_establishment_type (code, label) VALUES ('charcuterie', 'Charcuterie') ON DUPLICATE KEY UPDATE label = VALUES(label)");
        $this->addSql("INSERT INTO merchant_establishment_type (code, label) VALUES ('poissonnerie', 'Poissonnerie') ON DUPLICATE KEY UPDATE label = VALUES(label)");
        $this->addSql("INSERT INTO merchant_establishment_type (code, label) VALUES ('patisserie', 'Patisserie') ON DUPLICATE KEY UPDATE label = VALUES(label)");
        $this->addSql("INSERT INTO merchant_establishment_type (code, label) VALUES ('traiteur', 'Traiteur') ON DUPLICATE KEY UPDATE label = VALUES(label)");

        $this->addSql("SET @has_new_col := (SELECT COUNT(1) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'merchant' AND column_name = 'establishment_type_id')");
        $this->addSql("SET @add_col_sql := IF(@has_new_col = 0, 'ALTER TABLE merchant ADD establishment_type_id INT DEFAULT NULL', 'SELECT 1')");
        $this->addSql('PREPARE stmt_add_col FROM @add_col_sql');
        $this->addSql('EXECUTE stmt_add_col');
        $this->addSql('DEALLOCATE PREPARE stmt_add_col');

        $this->addSql("SET @idx_exists := (SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'merchant' AND index_name = 'IDX_MERCHANT_ESTABLISHMENT_TYPE_ID')");
        $this->addSql("SET @idx_sql := IF(@idx_exists = 0, 'CREATE INDEX IDX_MERCHANT_ESTABLISHMENT_TYPE_ID ON merchant (establishment_type_id)', 'SELECT 1')");
        $this->addSql('PREPARE stmt_idx FROM @idx_sql');
        $this->addSql('EXECUTE stmt_idx');
        $this->addSql('DEALLOCATE PREPARE stmt_idx');

        $this->addSql("SET @fk_exists := (SELECT COUNT(1) FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE() AND table_name = 'merchant' AND constraint_name = 'FK_MERCHANT_ESTABLISHMENT_TYPE_ID')");
        $this->addSql("SET @fk_sql := IF(@fk_exists = 0, 'ALTER TABLE merchant ADD CONSTRAINT FK_MERCHANT_ESTABLISHMENT_TYPE_ID FOREIGN KEY (establishment_type_id) REFERENCES merchant_establishment_type (id) ON DELETE SET NULL', 'SELECT 1')");
        $this->addSql('PREPARE stmt_fk FROM @fk_sql');
        $this->addSql('EXECUTE stmt_fk');
        $this->addSql('DEALLOCATE PREPARE stmt_fk');

        $this->addSql("SET @has_old_col := (SELECT COUNT(1) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'merchant' AND column_name = 'establishment_type')");
        $this->addSql("SET @drop_col_sql := IF(@has_old_col = 1, 'ALTER TABLE merchant DROP COLUMN establishment_type', 'SELECT 1')");
        $this->addSql('PREPARE stmt_drop_col FROM @drop_col_sql');
        $this->addSql('EXECUTE stmt_drop_col');
        $this->addSql('DEALLOCATE PREPARE stmt_drop_col');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE merchant ADD establishment_type VARCHAR(32) DEFAULT NULL');
        $this->addSql("UPDATE merchant m LEFT JOIN merchant_establishment_type met ON met.id = m.establishment_type_id SET m.establishment_type = met.code WHERE m.establishment_type_id IS NOT NULL");

        $this->addSql('ALTER TABLE merchant DROP FOREIGN KEY FK_MERCHANT_ESTABLISHMENT_TYPE_ID');
        $this->addSql('DROP INDEX IDX_MERCHANT_ESTABLISHMENT_TYPE_ID ON merchant');
        $this->addSql('ALTER TABLE merchant DROP establishment_type_id');

        $this->addSql('DROP TABLE merchant_establishment_type');
    }
}
