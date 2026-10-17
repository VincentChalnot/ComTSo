<?php

namespace App\Legacy\Source;

use App\Legacy\LegacyDate;
use App\Legacy\Model\LegacyMessage;
use App\Legacy\Model\LegacyPost;
use App\Legacy\Model\LegacyTopic;
use App\Legacy\Model\LegacyUser;
use App\Legacy\Text\Phpbb3Converter;
use App\Legacy\Text\TextRepair;
use Doctrine\DBAL\Connection;

/** phpBB 3.0 tables (`phpbb3_*`) of the PostgreSQL dump (2007-06 → 2009-06). */
final class Phpbb3Source
{
    public const NAME = 'phpbb3';

    public function __construct(
        private readonly Connection $db,
        private readonly Phpbb3Converter $converter = new Phpbb3Converter(),
    ) {
    }

    /** @return list<LegacyUser> real members only (bots and the guest account are skipped) */
    public function users(): array
    {
        $users = [];
        foreach ($this->db->fetchAllAssociative('SELECT user_id, username, user_email, user_regdate FROM phpbb3_users WHERE user_type IN (0, 3) ORDER BY user_id') as $r) {
            $users[] = new LegacyUser(self::NAME, (int) $r['user_id'], TextRepair::decodeEntities($r['username']), $r['user_email'] ?: null, LegacyDate::fromUnix((int) $r['user_regdate']));
        }

        return $users;
    }

    /** @return array<int, array{title: string, description: string}> */
    public function forums(): array
    {
        $forums = [];
        foreach ($this->db->fetchAllAssociative('SELECT forum_id, forum_name, forum_desc FROM phpbb3_forums WHERE forum_type = 1 ORDER BY forum_id') as $r) {
            $forums[(int) $r['forum_id']] = ['title' => TextRepair::decodeEntities($r['forum_name']), 'description' => TextRepair::decodeEntities((string) $r['forum_desc'])];
        }

        return $forums;
    }

    /** @return list<LegacyTopic> */
    public function topics(): array
    {
        $postsByTopic = [];
        $rows = $this->db->fetchAllAssociative(
            'SELECT post_id, topic_id, poster_id, post_time, post_username, post_approved, enable_bbcode, bbcode_uid, post_text
             FROM phpbb3_posts ORDER BY topic_id, post_time, post_id'
        );
        foreach ($rows as $r) {
            $guest = (int) $r['poster_id'] <= 1; // 1 = Anonymous
            $name = $guest ? trim(TextRepair::decodeEntities((string) $r['post_username'])) : null;
            $postsByTopic[(int) $r['topic_id']][] = new LegacyPost(
                self::NAME,
                (int) $r['post_id'],
                (int) $r['topic_id'],
                $guest ? null : (int) $r['poster_id'],
                '' !== (string) $name ? $name : null,
                LegacyDate::fromUnix((int) $r['post_time']),
                $this->converter->convert($r['post_text'], (string) $r['bbcode_uid'], (bool) $r['enable_bbcode']),
                (int) $r['post_approved'] ? null : 'not approved on phpBB 3',
            );
        }

        $topics = [];
        foreach ($this->db->fetchAllAssociative('SELECT topic_id, forum_id, topic_title, topic_poster, topic_time, topic_views FROM phpbb3_topics WHERE topic_status <> 2 ORDER BY topic_id') as $r) {
            $posts = $postsByTopic[(int) $r['topic_id']] ?? [];
            $topics[] = new LegacyTopic(
                self::NAME,
                (int) $r['topic_id'],
                (int) $r['forum_id'],
                trim(TextRepair::decodeEntities($r['topic_title'])),
                (int) $r['topic_poster'] > 1 ? (int) $r['topic_poster'] : null,
                $posts ? $posts[0]->createdAt : LegacyDate::fromUnix((int) $r['topic_time']),
                (int) $r['topic_views'],
                null,
                $posts,
            );
        }

        return $topics;
    }

    /**
     * Private messages, one per recipient (`to_address` = "u_54:u_2"; groups are not used on this board).
     *
     * @return list<LegacyMessage>
     */
    public function messages(): array
    {
        $messages = [];
        foreach ($this->db->fetchAllAssociative('SELECT msg_id, author_id, message_time, message_subject, message_text, bbcode_uid, enable_bbcode, to_address FROM phpbb3_privmsgs ORDER BY message_time, msg_id') as $r) {
            $html = $this->converter->convert($r['message_text'], (string) $r['bbcode_uid'], (bool) $r['enable_bbcode']);
            $subject = Phpbb2Source::cleanSubject(TextRepair::decodeEntities((string) $r['message_subject']));
            foreach (array_filter(explode(':', (string) $r['to_address'])) as $to) {
                if (!str_starts_with($to, 'u_')) {
                    continue;
                }
                $messages[] = new LegacyMessage(
                    self::NAME,
                    $r['msg_id'].':'.substr($to, 2),
                    (int) $r['author_id'] > 1 ? (int) $r['author_id'] : null,
                    (int) substr($to, 2),
                    LegacyDate::fromUnix((int) $r['message_time']),
                    $html,
                    $subject,
                );
            }
        }

        return $messages;
    }
}
