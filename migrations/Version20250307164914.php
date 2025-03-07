<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250307164914 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE product_picture (product_id INT NOT NULL, picture_id INT NOT NULL, INDEX IDX_C70254394584665A (product_id), INDEX IDX_C7025439EE45BDBF (picture_id), PRIMARY KEY(product_id, picture_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE product_booking (product_id INT NOT NULL, booking_id INT NOT NULL, INDEX IDX_31D5F66E4584665A (product_id), INDEX IDX_31D5F66E3301C60 (booking_id), PRIMARY KEY(product_id, booking_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE product_picture ADD CONSTRAINT FK_C70254394584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_picture ADD CONSTRAINT FK_C7025439EE45BDBF FOREIGN KEY (picture_id) REFERENCES picture (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_booking ADD CONSTRAINT FK_31D5F66E4584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_booking ADD CONSTRAINT FK_31D5F66E3301C60 FOREIGN KEY (booking_id) REFERENCES booking (id) ON DELETE CASCADE');
        $this->addSql('DROP TABLE role');
        $this->addSql('ALTER TABLE booking ADD user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE booking ADD CONSTRAINT FK_E00CEDDEA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('CREATE INDEX IDX_E00CEDDEA76ED395 ON booking (user_id)');
        $this->addSql('ALTER TABLE product DROP FOREIGN KEY FK_D34A04AD3301C60');
        $this->addSql('ALTER TABLE product DROP FOREIGN KEY FK_D34A04ADEE45BDBF');
        $this->addSql('DROP INDEX IDX_D34A04ADEE45BDBF ON product');
        $this->addSql('DROP INDEX IDX_D34A04AD3301C60 ON product');
        $this->addSql('ALTER TABLE product DROP picture_id, DROP booking_id, CHANGE title title VARCHAR(50) NOT NULL, CHANGE description description LONGTEXT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE role (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(32) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE product_picture DROP FOREIGN KEY FK_C70254394584665A');
        $this->addSql('ALTER TABLE product_picture DROP FOREIGN KEY FK_C7025439EE45BDBF');
        $this->addSql('ALTER TABLE product_booking DROP FOREIGN KEY FK_31D5F66E4584665A');
        $this->addSql('ALTER TABLE product_booking DROP FOREIGN KEY FK_31D5F66E3301C60');
        $this->addSql('DROP TABLE product_picture');
        $this->addSql('DROP TABLE product_booking');
        $this->addSql('ALTER TABLE booking DROP FOREIGN KEY FK_E00CEDDEA76ED395');
        $this->addSql('DROP INDEX IDX_E00CEDDEA76ED395 ON booking');
        $this->addSql('ALTER TABLE booking DROP user_id');
        $this->addSql('ALTER TABLE product ADD picture_id INT DEFAULT NULL, ADD booking_id INT DEFAULT NULL, CHANGE title title VARCHAR(32) NOT NULL, CHANGE description description VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_D34A04AD3301C60 FOREIGN KEY (booking_id) REFERENCES booking (id)');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_D34A04ADEE45BDBF FOREIGN KEY (picture_id) REFERENCES picture (id)');
        $this->addSql('CREATE INDEX IDX_D34A04ADEE45BDBF ON product (picture_id)');
        $this->addSql('CREATE INDEX IDX_D34A04AD3301C60 ON product (booking_id)');
    }
}
