<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260403092623 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE customer (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, email VARCHAR(180) NOT NULL, phone VARCHAR(20) DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE loyalty_card (id INT AUTO_INCREMENT NOT NULL, qr_code VARCHAR(255) NOT NULL, points INT NOT NULL, merchant_id INT NOT NULL, loyalty_program_id INT NOT NULL, customer_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_EAB8BCBD7D8B1FB5 (qr_code), INDEX IDX_EAB8BCBD6796D554 (merchant_id), INDEX IDX_EAB8BCBD8364CCED (loyalty_program_id), INDEX IDX_EAB8BCBD9395C3F3 (customer_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE loyalty_program (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, points_per_purchase INT NOT NULL, points_for_reward INT NOT NULL, reward_description VARCHAR(255) DEFAULT NULL, merchant_id INT NOT NULL, INDEX IDX_FE2C9F376796D554 (merchant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE merchant (id INT AUTO_INCREMENT NOT NULL, company_name VARCHAR(255) NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_74AB25E1A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE transaction (id INT AUTO_INCREMENT NOT NULL, points_earned INT NOT NULL, points_redeemed INT NOT NULL, created_at DATETIME NOT NULL, merchant_id INT NOT NULL, loyalty_card_id INT NOT NULL, INDEX IDX_723705D16796D554 (merchant_id), INDEX IDX_723705D1260D7293 (loyalty_card_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE loyalty_card ADD CONSTRAINT FK_EAB8BCBD6796D554 FOREIGN KEY (merchant_id) REFERENCES merchant (id)');
        $this->addSql('ALTER TABLE loyalty_card ADD CONSTRAINT FK_EAB8BCBD8364CCED FOREIGN KEY (loyalty_program_id) REFERENCES loyalty_program (id)');
        $this->addSql('ALTER TABLE loyalty_card ADD CONSTRAINT FK_EAB8BCBD9395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id)');
        $this->addSql('ALTER TABLE loyalty_program ADD CONSTRAINT FK_FE2C9F376796D554 FOREIGN KEY (merchant_id) REFERENCES merchant (id)');
        $this->addSql('ALTER TABLE merchant ADD CONSTRAINT FK_74AB25E1A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE transaction ADD CONSTRAINT FK_723705D16796D554 FOREIGN KEY (merchant_id) REFERENCES merchant (id)');
        $this->addSql('ALTER TABLE transaction ADD CONSTRAINT FK_723705D1260D7293 FOREIGN KEY (loyalty_card_id) REFERENCES loyalty_card (id)');
        $this->addSql('ALTER TABLE user ADD name VARCHAR(255) DEFAULT NULL, ADD google_id VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE loyalty_card DROP FOREIGN KEY FK_EAB8BCBD6796D554');
        $this->addSql('ALTER TABLE loyalty_card DROP FOREIGN KEY FK_EAB8BCBD8364CCED');
        $this->addSql('ALTER TABLE loyalty_card DROP FOREIGN KEY FK_EAB8BCBD9395C3F3');
        $this->addSql('ALTER TABLE loyalty_program DROP FOREIGN KEY FK_FE2C9F376796D554');
        $this->addSql('ALTER TABLE merchant DROP FOREIGN KEY FK_74AB25E1A76ED395');
        $this->addSql('ALTER TABLE transaction DROP FOREIGN KEY FK_723705D16796D554');
        $this->addSql('ALTER TABLE transaction DROP FOREIGN KEY FK_723705D1260D7293');
        $this->addSql('DROP TABLE customer');
        $this->addSql('DROP TABLE loyalty_card');
        $this->addSql('DROP TABLE loyalty_program');
        $this->addSql('DROP TABLE merchant');
        $this->addSql('DROP TABLE transaction');
        $this->addSql('ALTER TABLE `user` DROP name, DROP google_id');
    }
}
