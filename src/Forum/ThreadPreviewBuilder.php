<?php

namespace App\Forum;

use App\Content\TextExcerpt;
use App\Entity\Comment;
use App\Entity\Photo;
use App\Entity\Topic;
use App\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds the {@see ThreadPreview} of a whole page of topics with a fixed number of queries (no N+1):
 * everything besides the topics themselves is fetched for all topic ids at once, with grouped queries and
 * window functions that stay on the `(topic_id, created_at)` index of the message table.
 */
class ThreadPreviewBuilder
{
    public const EXCERPT_LENGTH = 220;
    public const REPLY_LENGTH = 160;
    public const MAX_PARTICIPANTS = 5;
    /** How many of the newest messages are searched for a cover photo when the topic itself has none. */
    public const COVER_LOOKBACK = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TextExcerpt $text,
    ) {
    }

    /**
     * @param iterable<Topic> $topics
     * @param User|null       $viewer the member looking at the page: "new" messages are those posted after their previous login
     *
     * @return list<ThreadPreview> in the order of $topics
     */
    public function build(iterable $topics, ?User $viewer = null): array
    {
        $byId = [];
        foreach ($topics as $topic) {
            $byId[$topic->getId()] = $topic;
        }
        if (!$byId) {
            return [];
        }
        $ids = array_keys($byId);

        $excerpts = [];
        $descriptionMissing = [];
        foreach ($byId as $id => $topic) {
            $excerpts[$id] = $this->text->excerpt($topic->getContent(), self::EXCERPT_LENGTH);
            if ('' === $excerpts[$id]) {
                $descriptionMissing[$id] = true;
            }
        }

        $ranked = $this->rankedMessages($ids);
        $comments = $this->loadComments($ranked, $descriptionMissing);
        $recentIds = [];
        foreach ($ranked as $row) {
            array_push($recentIds, ...$row['recent']);
        }
        $topicPhotos = $this->topicPhotos($ids);
        $messagePhotoCounts = $this->messagePhotoCounts($ids);
        $photoOfComment = $this->firstPhotoOfComments($recentIds);
        $participantStats = $this->participantStats($ids);
        $newCounts = $this->newCounts($ids, $viewer?->getPreviousLogin());

        $covers = [];
        $authorIds = [];
        foreach ($byId as $id => $topic) {
            $cover = $topicPhotos[$id]['photo'] ?? null;
            if (null === $cover) {
                foreach ($ranked[$id]['recent'] ?? [] as $commentId) {
                    if (isset($photoOfComment[$commentId])) {
                        $cover = $photoOfComment[$commentId];
                        break;
                    }
                }
            }
            if (null !== $cover) {
                $covers[$id] = $cover;
            }
            foreach ($participantStats[$id] ?? [] as $row) {
                $authorIds[$row['author']] = true;
            }
        }
        $photos = $this->entities(Photo::class, array_values(array_unique($covers)));
        $users = $this->entities(User::class, array_keys($authorIds));

        $previews = [];
        foreach ($byId as $id => $topic) {
            $firstId = $ranked[$id]['first'] ?? null;
            $lastId = $ranked[$id]['recent'][1] ?? null;

            $excerpt = $excerpts[$id];
            $excerptCommentId = null;
            if ('' === $excerpt && null !== $firstId && isset($comments[$firstId])) {
                $excerpt = $this->text->excerpt($comments[$firstId]->getContent(), self::EXCERPT_LENGTH);
                $excerptCommentId = '' !== $excerpt ? $firstId : null;
            }

            // A message that already serves as the standfirst is not repeated as "last reply".
            $lastReply = null;
            if (null !== $lastId && $lastId !== $excerptCommentId && isset($comments[$lastId])) {
                $last = $comments[$lastId];
                $reply = $this->text->excerpt($last->getContent(), self::REPLY_LENGTH);
                $hasPhoto = isset($photoOfComment[$lastId]);
                if ('' !== $reply || $hasPhoto) {
                    $lastReply = new LastReply($last, $last->getAuthor(), $reply, $hasPhoto);
                }
            }

            $stats = $participantStats[$id] ?? [];
            usort($stats, static fn (array $a, array $b) => [$b['count'], $b['last'], $a['author']] <=> [$a['count'], $a['last'], $b['author']]);
            $participants = [];
            foreach ($stats as $row) {
                if (isset($users[$row['author']])) {
                    $participants[] = $users[$row['author']];
                }
            }
            if (!$participants && $topic->getAuthor()) {
                $participants = [$topic->getAuthor()];
            }

            $previews[] = new ThreadPreview(
                topic: $topic,
                coverPhoto: isset($covers[$id]) ? ($photos[$covers[$id]] ?? null) : null,
                excerpt: $excerpt,
                lastReply: $lastReply,
                participants: \array_slice($participants, 0, self::MAX_PARTICIPANTS),
                moreParticipants: max(0, \count($participants) - self::MAX_PARTICIPANTS),
                messageCount: $topic->getCommentCount(),
                viewCount: $topic->getViews(),
                photoCount: ($topicPhotos[$id]['count'] ?? 0) + ($messagePhotoCounts[$id] ?? 0),
                newCount: $newCounts[$id] ?? 0,
            );
        }

        return $previews;
    }

    /**
     * The newest COVER_LOOKBACK message ids of every topic (key 1 = newest) and the id of its first message,
     * in one pass over the covering index.
     *
     * @param list<int> $ids
     *
     * @return array<int, array{recent: array<int, int>, first: int|null}>
     */
    private function rankedMessages(array $ids): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, topic_id, rn_last, rn_first FROM (
                SELECT id, topic_id,
                    ROW_NUMBER() OVER (PARTITION BY topic_id ORDER BY created_at DESC, id DESC) AS rn_last,
                    ROW_NUMBER() OVER (PARTITION BY topic_id ORDER BY created_at ASC, id ASC) AS rn_first
                FROM ctso_comment WHERE topic_id IN (?)
            ) ranked WHERE rn_last <= '.self::COVER_LOOKBACK.' OR rn_first = 1',
            [$ids],
            [ArrayParameterType::INTEGER],
        );

        $ranked = [];
        foreach ($rows as $row) {
            $topicId = (int) $row['topic_id'];
            $ranked[$topicId] ??= ['recent' => [], 'first' => null];
            if ((int) $row['rn_last'] <= self::COVER_LOOKBACK) {
                $ranked[$topicId]['recent'][(int) $row['rn_last']] = (int) $row['id'];
            }
            if (1 === (int) $row['rn_first']) {
                $ranked[$topicId]['first'] = (int) $row['id'];
            }
        }
        foreach ($ranked as &$row) {
            ksort($row['recent']);
        }
        unset($row);

        return $ranked;
    }

    /**
     * The newest message of every topic, and the first one of the topics that have no description.
     *
     * @param array<int, array{recent: array<int, int>, first: int|null}> $ranked
     * @param array<int, true>                                            $descriptionMissing
     *
     * @return array<int, Comment> by id
     */
    private function loadComments(array $ranked, array $descriptionMissing): array
    {
        $ids = [];
        foreach ($ranked as $topicId => $row) {
            if (isset($row['recent'][1])) {
                $ids[$row['recent'][1]] = true;
            }
            if (isset($descriptionMissing[$topicId]) && null !== $row['first']) {
                $ids[$row['first']] = true;
            }
        }
        if (!$ids) {
            return [];
        }

        $byId = [];
        $comments = $this->em->createQuery('SELECT c, a FROM '.Comment::class.' c LEFT JOIN c.author a WHERE c.id IN (:ids)')
            ->setParameter('ids', array_keys($ids))
            ->getResult();
        foreach ($comments as $comment) {
            $byId[$comment->getId()] = $comment;
        }

        return $byId;
    }

    /**
     * First photo (by member-chosen order) and number of photos attached to each topic.
     *
     * @param list<int> $ids
     *
     * @return array<int, array{photo: int, count: int}>
     */
    private function topicPhotos(array $ids): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT topic_id, photo_id, n FROM (
                SELECT topic_id, photo_id,
                    ROW_NUMBER() OVER (PARTITION BY topic_id ORDER BY ord, id) AS rn,
                    COUNT(*) OVER (PARTITION BY topic_id) AS n
                FROM ctso_photo_topic WHERE topic_id IN (?)
            ) ranked WHERE rn = 1',
            [$ids],
            [ArrayParameterType::INTEGER],
        );

        $photos = [];
        foreach ($rows as $row) {
            $photos[(int) $row['topic_id']] = ['photo' => (int) $row['photo_id'], 'count' => (int) $row['n']];
        }

        return $photos;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, int> number of photos attached to the messages of each topic
     */
    private function messagePhotoCounts(array $ids): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT c.topic_id, COUNT(*) AS n FROM ctso_comment_photo cp
                INNER JOIN ctso_comment c ON c.id = cp.comment_id
                WHERE c.topic_id IN (?) GROUP BY c.topic_id',
            [$ids],
            [ArrayParameterType::INTEGER],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['topic_id']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * @param array<int, int> $commentIds
     *
     * @return array<int, int> first photo id (by order) of every message that has photos
     */
    private function firstPhotoOfComments(array $commentIds): array
    {
        if (!$commentIds) {
            return [];
        }

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT comment_id, photo_id FROM ctso_comment_photo WHERE comment_id IN (?) ORDER BY ord, id',
            [array_values($commentIds)],
            [ArrayParameterType::INTEGER],
        );

        $photos = [];
        foreach ($rows as $row) {
            $photos[(int) $row['comment_id']] ??= (int) $row['photo_id'];
        }

        return $photos;
    }

    /**
     * Messages per author and topic (authors that were deleted are ignored).
     *
     * @param list<int> $ids
     *
     * @return array<int, list<array{author: int, count: int, last: string}>>
     */
    private function participantStats(array $ids): array
    {
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(c.topic) AS topic, IDENTITY(c.author) AS author, COUNT(c.id) AS cnt, MAX(c.createdAt) AS last
             FROM '.Comment::class.' c WHERE c.topic IN (:ids) AND c.author IS NOT NULL GROUP BY c.topic, c.author',
        )->setParameter('ids', $ids)->getArrayResult();

        $stats = [];
        foreach ($rows as $row) {
            $stats[(int) $row['topic']][] = ['author' => (int) $row['author'], 'count' => (int) $row['cnt'], 'last' => (string) $row['last']];
        }

        return $stats;
    }

    /**
     * Messages posted since a date, like {@see \App\Repository\CommentRepository::countSince()} but for many topics.
     *
     * @param list<int> $ids
     *
     * @return array<int, int>
     */
    private function newCounts(array $ids, ?\DateTimeInterface $since): array
    {
        if (null === $since) {
            return [];
        }

        $rows = $this->em->createQuery(
            'SELECT IDENTITY(c.topic) AS topic, COUNT(c.id) AS cnt FROM '.Comment::class.' c
             WHERE c.topic IN (:ids) AND c.createdAt >= :since GROUP BY c.topic',
        )->setParameter('ids', $ids)->setParameter('since', $since, Types::DATETIME_MUTABLE)->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['topic']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     * @param list<int>       $ids
     *
     * @return array<int, T> by id
     */
    private function entities(string $class, array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $byId = [];
        foreach ($this->em->getRepository($class)->findBy(['id' => $ids]) as $entity) {
            $byId[$entity->getId()] = $entity;
        }

        return $byId;
    }
}
