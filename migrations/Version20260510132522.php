<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260510132522 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ctso_chat_message (id INT AUTO_INCREMENT NOT NULL, content LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, author_id INT DEFAULT NULL, INDEX IDX_8033519EF675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ctso_comment (id INT AUTO_INCREMENT NOT NULL, content LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, topic_id INT NOT NULL, author_id INT DEFAULT NULL, INDEX IDX_715845701F55203D (topic_id), INDEX IDX_71584570F675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ctso_forum (id VARCHAR(32) NOT NULL, title VARCHAR(128) NOT NULL, content LONGTEXT DEFAULT NULL, ord INT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, author_id INT DEFAULT NULL, INDEX IDX_95E51C51F675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ctso_message (id INT AUTO_INCREMENT NOT NULL, content LONGTEXT DEFAULT NULL, state INT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, author_id INT DEFAULT NULL, recipient_id INT NOT NULL, INDEX IDX_53912763F675F31B (author_id), INDEX IDX_53912763E92F8F78 (recipient_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ctso_photo (id INT AUTO_INCREMENT NOT NULL, filename VARCHAR(128) NOT NULL, original_filename VARCHAR(128) NOT NULL, file_size INT NOT NULL, file_type VARCHAR(128) NOT NULL, file_modified_at DATETIME DEFAULT NULL, taken_at DATETIME DEFAULT NULL, width INT DEFAULT NULL, height INT DEFAULT NULL, exif JSON DEFAULT NULL, title VARCHAR(128) DEFAULT NULL, content LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, author_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_47926843C0BE965 (filename), INDEX IDX_4792684F675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ctso_photo_topic (id INT AUTO_INCREMENT NOT NULL, ord INT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, topic_id INT NOT NULL, photo_id INT NOT NULL, author_id INT DEFAULT NULL, INDEX IDX_CDE6F9271F55203D (topic_id), INDEX IDX_CDE6F9277E9E4C8C (photo_id), INDEX IDX_CDE6F927F675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ctso_quote (id INT AUTO_INCREMENT NOT NULL, content LONGTEXT DEFAULT NULL, original_author VARCHAR(128) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, author_id INT DEFAULT NULL, INDEX IDX_7BBF6968F675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ctso_topic (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(128) NOT NULL, content LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, views INT NOT NULL, comment_count INT NOT NULL, forum_id VARCHAR(32) NOT NULL, author_id INT DEFAULT NULL, INDEX IDX_8D8E7C8729CCBAD0 (forum_id), INDEX IDX_8D8E7C87F675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE fos_user (id INT AUTO_INCREMENT NOT NULL, username VARCHAR(180) NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, name VARCHAR(32) DEFAULT NULL, surname VARCHAR(32) DEFAULT NULL, birthday DATE DEFAULT NULL, address LONGTEXT DEFAULT NULL, phone VARCHAR(20) DEFAULT NULL, activities LONGTEXT DEFAULT NULL, signature LONGTEXT DEFAULT NULL, website VARCHAR(128) DEFAULT NULL, registered_at DATETIME NOT NULL, previous_login DATETIME DEFAULT NULL, last_activity DATETIME DEFAULT NULL, last_login DATETIME DEFAULT NULL, config JSON DEFAULT NULL, avatar_id INT DEFAULT NULL, INDEX IDX_957A647986383B10 (avatar_id), UNIQUE INDEX UNIQ_username (username), UNIQUE INDEX UNIQ_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ctso_starred_topic (user_id INT NOT NULL, topic_id INT NOT NULL, INDEX IDX_30E4C5FFA76ED395 (user_id), INDEX IDX_30E4C5FF1F55203D (topic_id), PRIMARY KEY (user_id, topic_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE ctso_chat_message ADD CONSTRAINT FK_8033519EF675F31B FOREIGN KEY (author_id) REFERENCES fos_user (id)');
        $this->addSql('ALTER TABLE ctso_comment ADD CONSTRAINT FK_715845701F55203D FOREIGN KEY (topic_id) REFERENCES ctso_topic (id)');
        $this->addSql('ALTER TABLE ctso_comment ADD CONSTRAINT FK_71584570F675F31B FOREIGN KEY (author_id) REFERENCES fos_user (id)');
        $this->addSql('ALTER TABLE ctso_forum ADD CONSTRAINT FK_95E51C51F675F31B FOREIGN KEY (author_id) REFERENCES fos_user (id)');
        $this->addSql('ALTER TABLE ctso_message ADD CONSTRAINT FK_53912763F675F31B FOREIGN KEY (author_id) REFERENCES fos_user (id)');
        $this->addSql('ALTER TABLE ctso_message ADD CONSTRAINT FK_53912763E92F8F78 FOREIGN KEY (recipient_id) REFERENCES fos_user (id)');
        $this->addSql('ALTER TABLE ctso_photo ADD CONSTRAINT FK_4792684F675F31B FOREIGN KEY (author_id) REFERENCES fos_user (id)');
        $this->addSql('ALTER TABLE ctso_photo_topic ADD CONSTRAINT FK_CDE6F9271F55203D FOREIGN KEY (topic_id) REFERENCES ctso_topic (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ctso_photo_topic ADD CONSTRAINT FK_CDE6F9277E9E4C8C FOREIGN KEY (photo_id) REFERENCES ctso_photo (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ctso_photo_topic ADD CONSTRAINT FK_CDE6F927F675F31B FOREIGN KEY (author_id) REFERENCES fos_user (id)');
        $this->addSql('ALTER TABLE ctso_quote ADD CONSTRAINT FK_7BBF6968F675F31B FOREIGN KEY (author_id) REFERENCES fos_user (id)');
        $this->addSql('ALTER TABLE ctso_topic ADD CONSTRAINT FK_8D8E7C8729CCBAD0 FOREIGN KEY (forum_id) REFERENCES ctso_forum (id)');
        $this->addSql('ALTER TABLE ctso_topic ADD CONSTRAINT FK_8D8E7C87F675F31B FOREIGN KEY (author_id) REFERENCES fos_user (id)');
        $this->addSql('ALTER TABLE fos_user ADD CONSTRAINT FK_957A647986383B10 FOREIGN KEY (avatar_id) REFERENCES ctso_photo (id)');
        $this->addSql('ALTER TABLE ctso_starred_topic ADD CONSTRAINT FK_30E4C5FFA76ED395 FOREIGN KEY (user_id) REFERENCES fos_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ctso_starred_topic ADD CONSTRAINT FK_30E4C5FF1F55203D FOREIGN KEY (topic_id) REFERENCES ctso_topic (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ctso_chat_message DROP FOREIGN KEY FK_8033519EF675F31B');
        $this->addSql('ALTER TABLE ctso_comment DROP FOREIGN KEY FK_715845701F55203D');
        $this->addSql('ALTER TABLE ctso_comment DROP FOREIGN KEY FK_71584570F675F31B');
        $this->addSql('ALTER TABLE ctso_forum DROP FOREIGN KEY FK_95E51C51F675F31B');
        $this->addSql('ALTER TABLE ctso_message DROP FOREIGN KEY FK_53912763F675F31B');
        $this->addSql('ALTER TABLE ctso_message DROP FOREIGN KEY FK_53912763E92F8F78');
        $this->addSql('ALTER TABLE ctso_photo DROP FOREIGN KEY FK_4792684F675F31B');
        $this->addSql('ALTER TABLE ctso_photo_topic DROP FOREIGN KEY FK_CDE6F9271F55203D');
        $this->addSql('ALTER TABLE ctso_photo_topic DROP FOREIGN KEY FK_CDE6F9277E9E4C8C');
        $this->addSql('ALTER TABLE ctso_photo_topic DROP FOREIGN KEY FK_CDE6F927F675F31B');
        $this->addSql('ALTER TABLE ctso_quote DROP FOREIGN KEY FK_7BBF6968F675F31B');
        $this->addSql('ALTER TABLE ctso_topic DROP FOREIGN KEY FK_8D8E7C8729CCBAD0');
        $this->addSql('ALTER TABLE ctso_topic DROP FOREIGN KEY FK_8D8E7C87F675F31B');
        $this->addSql('ALTER TABLE fos_user DROP FOREIGN KEY FK_957A647986383B10');
        $this->addSql('ALTER TABLE ctso_starred_topic DROP FOREIGN KEY FK_30E4C5FFA76ED395');
        $this->addSql('ALTER TABLE ctso_starred_topic DROP FOREIGN KEY FK_30E4C5FF1F55203D');
        $this->addSql('DROP TABLE ctso_chat_message');
        $this->addSql('DROP TABLE ctso_comment');
        $this->addSql('DROP TABLE ctso_forum');
        $this->addSql('DROP TABLE ctso_message');
        $this->addSql('DROP TABLE ctso_photo');
        $this->addSql('DROP TABLE ctso_photo_topic');
        $this->addSql('DROP TABLE ctso_quote');
        $this->addSql('DROP TABLE ctso_topic');
        $this->addSql('DROP TABLE fos_user');
        $this->addSql('DROP TABLE ctso_starred_topic');
    }
}
