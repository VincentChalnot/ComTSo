<?php

namespace App\Legacy\Source;

use App\Legacy\LegacyDate;
use App\Legacy\Model\LegacyMessage;
use App\Legacy\Model\LegacyPhoto;
use App\Legacy\Model\LegacyPost;
use App\Legacy\Model\LegacyQuote;
use App\Legacy\Model\LegacyTopic;
use App\Legacy\Model\LegacyUser;
use App\Legacy\Text\OldPhpConverter;
use App\Legacy\Text\TextRepair;
use Doctrine\DBAL\Connection;

/** Tables of the old self-written PHP forum (`topics`, `posts`, `users`, `messages`, `quotes`, `photos`, `albums`). */
final class OldPhpSource
{
    public const NAME = 'oldphp';

    public function __construct(
        private readonly Connection $db,
        private readonly OldPhpConverter $converter = new OldPhpConverter(),
    ) {
    }

    /** @return list<LegacyUser> */
    public function users(): array
    {
        $users = [];
        foreach ($this->db->fetchAllAssociative('SELECT user_id, `user`, email, registered FROM users ORDER BY user_id') as $r) {
            $users[] = new LegacyUser(self::NAME, (int) $r['user_id'], TextRepair::fixMojibake($r['user']), $r['email'] ?: null, $r['registered'] ? LegacyDate::fromUnix((int) $r['registered']) : null);
        }

        return $users;
    }

    /** @return list<LegacyTopic> */
    public function topics(): array
    {
        $albumTopics = array_flip(array_map('intval', $this->db->fetchFirstColumn('SELECT topic_id FROM albums')));
        $postsByTopic = [];
        foreach ($this->db->fetchAllAssociative('SELECT post_id, user_id, topic_id, text, creation FROM posts ORDER BY topic_id, creation, post_id') as $r) {
            $postsByTopic[(int) $r['topic_id']][] = new LegacyPost(
                self::NAME,
                (int) $r['post_id'],
                (int) $r['topic_id'],
                (int) $r['user_id'],
                null,
                LegacyDate::fromUnix((int) $r['creation']),
                $this->converter->convert($r['text']),
            );
        }

        $topics = [];
        foreach ($this->db->fetchAllAssociative('SELECT * FROM topics ORDER BY topic_id') as $r) {
            $posts = $postsByTopic[(int) $r['topic_id']] ?? [];
            $summary = trim(TextRepair::fixMojibake((string) $r['summary']));
            $topics[] = new LegacyTopic(
                self::NAME,
                (int) $r['topic_id'],
                (int) $r['forum_id'],
                trim(TextRepair::decodeEntities(TextRepair::fixMojibake($r['title']))),
                (int) $r['user_id'],
                LegacyDate::fromUnix((int) $r['creation']),
                (int) $r['views'],
                '' !== $summary ? $this->converter->convert($summary) : null,
                $posts,
                isset($albumTopics[(int) $r['topic_id']]) ? 'album' : null,
            );
        }

        return $topics;
    }

    /** @return list<LegacyMessage> */
    public function messages(): array
    {
        $messages = [];
        foreach ($this->db->fetchAllAssociative('SELECT message_id, `from`, `to`, title, body, `date`, state FROM messages ORDER BY `date`, message_id') as $r) {
            $messages[] = new LegacyMessage(
                self::NAME,
                (string) $r['message_id'],
                (int) $r['from'],
                (int) $r['to'],
                LegacyDate::fromUnix((int) $r['date']),
                $this->converter->convert($r['body']),
                'Message Rapide' === trim($r['title']) ? null : Phpbb2Source::cleanSubject(TextRepair::decodeEntities(TextRepair::fixMojibake($r['title']))),
                // State 5 rows are the mirrored (from/to swapped) copy of another row: all 77 have their twin in the table.
                5 === (int) $r['state'] ? 'mirror copy (state 5) of another message' : null,
            );
        }

        return $messages;
    }

    /** @return list<LegacyQuote> */
    public function quotes(): array
    {
        $quotes = [];
        foreach ($this->db->fetchAllAssociative('SELECT quote_id, `text`, author, user_id FROM quotes ORDER BY quote_id') as $r) {
            $quotes[] = new LegacyQuote(
                self::NAME,
                (int) $r['quote_id'],
                $this->converter->convert($r['text']),
                trim(TextRepair::decodeEntities(TextRepair::fixMojibake($r['author']))),
                (int) $r['user_id'],
            );
        }

        return $quotes;
    }

    /** @return list<LegacyPhoto> */
    public function photos(): array
    {
        $photos = [];
        foreach ($this->db->fetchAllAssociative('SELECT photo_id, user_id, album_id, title, `date` FROM photos ORDER BY photo_id') as $r) {
            $photos[] = new LegacyPhoto(
                self::NAME,
                (int) $r['photo_id'],
                (int) $r['user_id'],
                (int) $r['album_id'],
                trim(TextRepair::decodeEntities(TextRepair::fixMojibake($r['title']))),
                LegacyDate::fromUnix((int) $r['date']),
            );
        }

        return $photos;
    }

    /** @return list<int> ids of the other photos of the same album */
    public function albumSiblings(int $albumId, int $photoId): array
    {
        return array_map('intval', $this->db->fetchFirstColumn('SELECT photo_id FROM photos WHERE album_id = ? AND photo_id <> ?', [$albumId, $photoId]));
    }
}
