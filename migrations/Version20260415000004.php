<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260415000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'V2 avec l\'ajout de compte client. Link customer to user account and support customer multi-merchant associations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer ADD user_id INT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_81398E09A76ED395 ON customer (user_id)');
        $this->addSql('ALTER TABLE customer ADD CONSTRAINT FK_81398E09A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE SET NULL');

        $this->addSql("CREATE TABLE customer_merchants (customer_id INT NOT NULL, merchant_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', INDEX IDX_835B66A89395C3F3 (customer_id), INDEX IDX_835B66A86796D554 (merchant_id), PRIMARY KEY(customer_id, merchant_id)) DEFAULT CHARACTER SET utf8mb4");
        $this->addSql('ALTER TABLE customer_merchants ADD CONSTRAINT FK_835B66A89395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_merchants ADD CONSTRAINT FK_835B66A86796D554 FOREIGN KEY (merchant_id) REFERENCES merchant (id) ON DELETE CASCADE');

        $this->addSql('INSERT IGNORE INTO customer_merchants (customer_id, merchant_id) SELECT id, merchant_id FROM customer WHERE merchant_id IS NOT NULL');
        $this->addSql('INSERT IGNORE INTO customer_merchants (customer_id, merchant_id) SELECT DISTINCT customer_id, merchant_id FROM loyalty_card WHERE customer_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_merchants DROP FOREIGN KEY FK_835B66A89395C3F3');
        $this->addSql('ALTER TABLE customer_merchants DROP FOREIGN KEY FK_835B66A86796D554');
        $this->addSql('DROP TABLE customer_merchants');

        $this->addSql('ALTER TABLE customer DROP FOREIGN KEY FK_81398E09A76ED395');
        $this->addSql('DROP INDEX UNIQ_81398E09A76ED395 ON customer');
        $this->addSql('ALTER TABLE customer DROP user_id');
    }
}
