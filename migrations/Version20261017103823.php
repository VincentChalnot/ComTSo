<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261017103823 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ctso_chat_message ADD legacy_ref VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8033519ECBA8A19F ON ctso_chat_message (legacy_ref)');
        $this->addSql('ALTER TABLE ctso_comment ADD legacy_ref VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_71584570CBA8A19F ON ctso_comment (legacy_ref)');
        $this->addSql('CREATE INDEX idx_comment_topic_created ON ctso_comment (topic_id, created_at)');
        $this->addSql('CREATE INDEX idx_comment_created ON ctso_comment (created_at)');
        $this->addSql('ALTER TABLE ctso_message ADD legacy_ref VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_53912763CBA8A19F ON ctso_message (legacy_ref)');
        $this->addSql('ALTER TABLE ctso_photo ADD legacy_ref VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4792684CBA8A19F ON ctso_photo (legacy_ref)');
        $this->addSql('ALTER TABLE ctso_quote ADD legacy_ref VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7BBF6968CBA8A19F ON ctso_quote (legacy_ref)');
        $this->addSql('ALTER TABLE ctso_topic ADD last_activity_at DATETIME DEFAULT NULL, ADD legacy_ref VARCHAR(64) DEFAULT NULL');
        $this->addSql('UPDATE ctso_topic t SET t.last_activity_at = GREATEST(t.created_at, COALESCE((SELECT MAX(c.created_at) FROM ctso_comment c WHERE c.topic_id = t.id), t.created_at))');
        $this->addSql('ALTER TABLE ctso_topic MODIFY last_activity_at DATETIME NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D8E7C87CBA8A19F ON ctso_topic (legacy_ref)');
        $this->addSql('CREATE INDEX idx_topic_last_activity ON ctso_topic (last_activity_at)');
        $this->addSql('ALTER TABLE fos_user ADD chat_last_read_id INT DEFAULT 0 NOT NULL, ADD legacy_ref VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_957A6479CBA8A19F ON fos_user (legacy_ref)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_8033519ECBA8A19F ON ctso_chat_message');
        $this->addSql('ALTER TABLE ctso_chat_message DROP legacy_ref');
        $this->addSql('DROP INDEX UNIQ_71584570CBA8A19F ON ctso_comment');
        $this->addSql('DROP INDEX idx_comment_topic_created ON ctso_comment');
        $this->addSql('DROP INDEX idx_comment_created ON ctso_comment');
        $this->addSql('ALTER TABLE ctso_comment DROP legacy_ref');
        $this->addSql('DROP INDEX UNIQ_53912763CBA8A19F ON ctso_message');
        $this->addSql('ALTER TABLE ctso_message DROP legacy_ref');
        $this->addSql('DROP INDEX UNIQ_4792684CBA8A19F ON ctso_photo');
        $this->addSql('ALTER TABLE ctso_photo DROP legacy_ref');
        $this->addSql('DROP INDEX UNIQ_7BBF6968CBA8A19F ON ctso_quote');
        $this->addSql('ALTER TABLE ctso_quote DROP legacy_ref');
        $this->addSql('DROP INDEX UNIQ_8D8E7C87CBA8A19F ON ctso_topic');
        $this->addSql('DROP INDEX idx_topic_last_activity ON ctso_topic');
        $this->addSql('ALTER TABLE ctso_topic DROP last_activity_at, DROP legacy_ref');
        $this->addSql('DROP INDEX UNIQ_957A6479CBA8A19F ON fos_user');
        $this->addSql('ALTER TABLE fos_user DROP chat_last_read_id, DROP legacy_ref');
    }
}
