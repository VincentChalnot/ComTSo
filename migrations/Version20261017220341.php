<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261017220341 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ctso_photo_like (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, photo_id INT NOT NULL, user_id INT NOT NULL, INDEX idx_photo_like_created (created_at), UNIQUE INDEX uniq_photo_like (user_id, photo_id), INDEX IDX_F3540C37E9E4C8C (photo_id), INDEX IDX_F3540C3A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE ctso_photo_like ADD CONSTRAINT FK_F3540C37E9E4C8C FOREIGN KEY (photo_id) REFERENCES ctso_photo (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ctso_photo_like ADD CONSTRAINT FK_F3540C3A76ED395 FOREIGN KEY (user_id) REFERENCES fos_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ctso_photo ADD gps_latitude NUMERIC(9, 6) DEFAULT NULL, ADD gps_longitude NUMERIC(10, 6) DEFAULT NULL, ADD gps_altitude NUMERIC(8, 2) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_photo_taken_at ON ctso_photo (taken_at)');
        $this->addSql('CREATE INDEX idx_photo_gps ON ctso_photo (gps_latitude, gps_longitude)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ctso_photo_like DROP FOREIGN KEY FK_F3540C37E9E4C8C');
        $this->addSql('ALTER TABLE ctso_photo_like DROP FOREIGN KEY FK_F3540C3A76ED395');
        $this->addSql('DROP TABLE ctso_photo_like');
        $this->addSql('DROP INDEX idx_photo_taken_at ON ctso_photo');
        $this->addSql('DROP INDEX idx_photo_gps ON ctso_photo');
        $this->addSql('ALTER TABLE ctso_photo DROP gps_latitude, DROP gps_longitude, DROP gps_altitude');
    }
}
