<?php

namespace App\Legacy\Source;

use App\Legacy\LegacyDate;
use App\Legacy\Model\LegacyMessage;
use App\Legacy\Model\LegacyPost;
use App\Legacy\Model\LegacyTopic;
use App\Legacy\Model\LegacyUser;
use App\Legacy\Text\Phpbb2Converter;
use App\Legacy\Text\TextRepair;
use Doctrine\DBAL\Connection;

/** phpBB 2 tables (`phpbb_*`) of the MySQL dump (2004-04 → 2007-06). */
final class Phpbb2Source
{
    public const NAME = 'phpbb2';

    public function __construct(
        private readonly Connection $db,
        private readonly Phpbb2Converter $converter = new Phpbb2Converter(),
    ) {
    }

    /** @return list<LegacyUser> */
    public function users(): array
    {
        $users = [];
        foreach ($this->db->fetchAllAssociative('SELECT user_id, username, user_email, user_regdate FROM phpbb_users WHERE user_id > 0 ORDER BY user_id') as $r) {
            $users[] = new LegacyUser(self::NAME, (int) $r['user_id'], TextRepair::decodeEntities($r['username']), $r['user_email'] ?: null, LegacyDate::fromUnix((int) $r['user_regdate']));
        }

        return $users;
    }

    /** @return array<int, array{title: string, description: string, category: string}> forum id => data */
    public function forums(): array
    {
        $forums = [];
        foreach ($this->db->fetchAllAssociative('SELECT f.forum_id, f.forum_name, f.forum_desc, c.cat_title FROM phpbb_forums f LEFT JOIN phpbb_categories c ON c.cat_id = f.cat_id ORDER BY f.forum_id') as $r) {
            $forums[(int) $r['forum_id']] = [
                'title' => TextRepair::decodeEntities($r['forum_name']),
                'description' => trim(strip_tags(str_replace(["\r", "\n"], ' ', TextRepair::decodeEntities((string) $r['forum_desc'])))),
                'category' => TextRepair::decodeEntities((string) $r['cat_title']),
            ];
        }

        return $forums;
    }

    /** @return list<LegacyTopic> moved-topic shadows and topics without any post are left out and counted by the caller */
    public function topics(): array
    {
        $postsByTopic = [];
        $rows = $this->db->fetchAllAssociative(
            'SELECT p.post_id, p.topic_id, p.poster_id, p.post_time, p.post_username, p.enable_bbcode, p.enable_html, p.enable_smilies,
                    t.bbcode_uid, t.post_text
             FROM phpbb_posts p JOIN phpbb_posts_text t ON t.post_id = p.post_id
             ORDER BY p.topic_id, p.post_time, p.post_id'
        );
        foreach ($rows as $r) {
            $guest = (int) $r['poster_id'] <= 0;
            $name = $guest ? trim(TextRepair::decodeEntities((string) $r['post_username'])) : null;
            $postsByTopic[(int) $r['topic_id']][] = new LegacyPost(
                self::NAME,
                (int) $r['post_id'],
                (int) $r['topic_id'],
                $guest ? null : (int) $r['poster_id'],
                '' !== (string) $name ? $name : null,
                LegacyDate::fromUnix((int) $r['post_time']),
                $this->converter->convert($r['post_text'], (string) $r['bbcode_uid'], (bool) $r['enable_bbcode'], (bool) $r['enable_smilies'], (bool) $r['enable_html']),
            );
        }

        $topics = [];
        foreach ($this->db->fetchAllAssociative('SELECT * FROM phpbb_topics WHERE topic_moved_id = 0 ORDER BY topic_id') as $r) {
            $posts = $postsByTopic[(int) $r['topic_id']] ?? [];
            $topics[] = new LegacyTopic(
                self::NAME,
                (int) $r['topic_id'],
                (int) $r['forum_id'],
                trim(TextRepair::decodeEntities($r['topic_title'])),
                (int) $r['topic_poster'] > 0 ? (int) $r['topic_poster'] : null,
                $posts ? $posts[0]->createdAt : LegacyDate::fromUnix((int) $r['topic_time']),
                (int) $r['topic_views'],
                null,
                $posts,
            );
        }

        return $topics;
    }

    /**
     * Private messages. phpBB 2 keeps one row per mailbox copy (inbox type 0/1/5/3, sentbox 2/4): copies of the
     * same message (sender, recipient, date) are collapsed.
     *
     * @return list<LegacyMessage>
     */
    public function messages(): array
    {
        $seen = [];
        $messages = [];
        $rows = $this->db->fetchAllAssociative(
            'SELECT m.privmsgs_id, m.privmsgs_type, m.privmsgs_subject, m.privmsgs_from_userid, m.privmsgs_to_userid, m.privmsgs_date,
                    m.privmsgs_enable_bbcode, m.privmsgs_enable_smilies, t.privmsgs_bbcode_uid, t.privmsgs_text
             FROM phpbb_privmsgs m JOIN phpbb_privmsgs_text t ON t.privmsgs_text_id = m.privmsgs_id
             ORDER BY m.privmsgs_date, m.privmsgs_id'
        );
        foreach ($rows as $r) {
            $key = $r['privmsgs_from_userid'].'|'.$r['privmsgs_to_userid'].'|'.$r['privmsgs_date'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $html = $this->converter->convert($r['privmsgs_text'], (string) $r['privmsgs_bbcode_uid'], (bool) $r['privmsgs_enable_bbcode'], (bool) $r['privmsgs_enable_smilies'], false);
            $messages[] = new LegacyMessage(
                self::NAME,
                (string) $r['privmsgs_id'],
                (int) $r['privmsgs_from_userid'] > 0 ? (int) $r['privmsgs_from_userid'] : null,
                (int) $r['privmsgs_to_userid'],
                LegacyDate::fromUnix((int) $r['privmsgs_date']),
                $html,
                self::cleanSubject(TextRepair::decodeEntities((string) $r['privmsgs_subject'])),
            );
        }

        return $messages;
    }

    /** Subjects of plain replies ("Re: …") carry no information and are dropped. */
    public static function cleanSubject(string $subject): ?string
    {
        $subject = trim($subject);
        if ('' === $subject || 1 === preg_match('/^(re|tr|fwd?)\s*:/i', $subject)) {
            return null;
        }

        return $subject;
    }

    /** Stored body of a private message: the subject, when any, becomes a bold first line. */
    public static function withSubject(?string $subject, string $html): string
    {
        return null === $subject ? $html : '<p><strong>'.htmlspecialchars($subject, \ENT_NOQUOTES).'</strong></p>'.$html;
    }
}
