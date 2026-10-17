<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261017142840 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ctso_comment_photo (id INT AUTO_INCREMENT NOT NULL, ord INT DEFAULT NULL, created_at DATETIME NOT NULL, comment_id INT NOT NULL, photo_id INT NOT NULL, author_id INT DEFAULT NULL, UNIQUE INDEX uniq_comment_photo (comment_id, photo_id), INDEX IDX_5E349756F8697D13 (comment_id), INDEX IDX_5E3497567E9E4C8C (photo_id), INDEX IDX_5E349756F675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE ctso_comment_photo ADD CONSTRAINT FK_5E349756F8697D13 FOREIGN KEY (comment_id) REFERENCES ctso_comment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ctso_comment_photo ADD CONSTRAINT FK_5E3497567E9E4C8C FOREIGN KEY (photo_id) REFERENCES ctso_photo (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ctso_comment_photo ADD CONSTRAINT FK_5E349756F675F31B FOREIGN KEY (author_id) REFERENCES fos_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ctso_comment_photo DROP FOREIGN KEY FK_5E349756F8697D13');
        $this->addSql('ALTER TABLE ctso_comment_photo DROP FOREIGN KEY FK_5E3497567E9E4C8C');
        $this->addSql('ALTER TABLE ctso_comment_photo DROP FOREIGN KEY FK_5E349756F675F31B');
        $this->addSql('DROP TABLE ctso_comment_photo');
    }
}
