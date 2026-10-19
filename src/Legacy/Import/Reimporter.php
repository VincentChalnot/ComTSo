<?php

namespace App\Legacy\Import;

use App\Content\ContentSanitizer;
use App\Legacy\LegacyDate;
use App\Legacy\Model\LegacyMessage;
use App\Legacy\Model\LegacyPost;
use App\Legacy\Model\LegacyTopic;
use App\Legacy\Source\OldPhpSource;
use App\Legacy\Source\Phpbb2Source;
use App\Legacy\Source\Phpbb3Source;
use App\Legacy\Text\TextNormalizer;
use App\Photo\PhotoPipeline;
use Doctrine\DBAL\Connection;

/**
 * Recovers what the Symfony 2 migration missed from the three older platforms, without ever duplicating
 * a message that is already in the live database (see {@see LiveIndex} for how "already there" is decided).
 */
final class Reimporter
{
    public const SOURCES = ['phpbb2', 'phpbb3', 'oldphp'];

    private ImportReport $report;
    private ReimportOptions $options;
    private UserMapper $users;
    private ForumMapper $forums;
    private LiveIndex $comments;
    private LiveIndex $messages;
    private LiveIndex $quotes;
    private PhotoLinks $photoLinks;
    /** @var array<string, list<array{id: int, ts: int}>> normalised title => live topics */
    private array $topicsByTitle = [];
    /** @var array<int, true> live topics already taken by a legacy topic of the current source */
    private array $topicClaims = [];
    /** @var array<string, true> */
    private array $existingRefs = [];
    /** @var array<int, true> topics whose counters must be recomputed */
    private array $touchedTopics = [];
    /** @var array<string, true> smiley files referenced by imported HTML */
    private array $smilies = [];
    private int $fakeId = 0;
    /** @var array<string, int> phpBB post ref => live comment id (matched or inserted), for the attachments */
    private array $postComments = [];

    public function __construct(
        private readonly Connection $live,
        private readonly ContentSanitizer $sanitizer,
        private readonly Phpbb2Source $phpbb2,
        private readonly Phpbb3Source $phpbb3,
        private readonly OldPhpSource $oldPhp,
        private readonly PhotoPipeline $photoPipeline,
    ) {
    }

    public function run(ReimportOptions $options): ImportReport
    {
        $this->options = $options;
        $this->report = new ImportReport();
        $this->users = new UserMapper($this->live, $options->dryRun);
        $this->forums = new ForumMapper($this->live, $options->dryRun);
        $this->topicClaims = $this->touchedTopics = $this->smilies = $this->existingRefs = $this->topicsByTitle = $this->postComments = [];

        // Every platform's accounts are registered first: identities are matched across platforms.
        $this->users->register($this->phpbb3->users());
        $this->users->register($this->oldPhp->users());
        $this->users->register($this->phpbb2->users());

        $this->photoLinks = new PhotoLinks($this->live);
        $this->loadLiveIndexes();

        if (!$options->dryRun) {
            $this->live->beginTransaction();
        }
        try {
            foreach (self::SOURCES as $source) {
                if (!\in_array($source, $options->sources, true)) {
                    continue;
                }
                $this->comments->resetClaims();
                $this->messages->resetClaims();
                $this->quotes->resetClaims();
                $this->topicClaims = [];
                match ($source) {
                    'phpbb2' => $this->importPlatform('phpbb2', $this->phpbb2->topics(), $this->phpbb2->forums(), $this->phpbb2->messages()),
                    'phpbb3' => $this->importPhpbb3(),
                    'oldphp' => $this->importOldPhp(),
                };
            }
            if (!$options->dryRun) {
                $this->recomputeTopics();
                $this->live->commit();
            }
        } catch (\Throwable $e) {
            if (!$options->dryRun && $this->live->isTransactionActive()) {
                $this->live->rollBack();
            }
            throw $e;
        }

        foreach ($this->forums->createdLog() as $line) {
            $this->report->note(($options->dryRun ? 'would create' : 'created').' forum '.$line);
        }
        foreach ($this->users->createdLog() as $line) {
            $this->report->note(($options->dryRun ? 'would create' : 'created').' disabled user '.$line);
        }
        $this->installSmilies();
        foreach ($this->users->mappingLog() as $line) {
            $this->report->detail('user mapping', $line);
        }
        foreach ($this->report->details() as $group => $lines) {
            if (str_contains($group, 'fuzzy matches')) {
                $this->report->note(sprintf('%d %s (-v lists them)', \count($lines), $group));
            }
        }

        return $this->report;
    }

    // ------------------------------------------------------------------ live state

    private function loadLiveIndexes(): void
    {
        $this->comments = new LiveIndex($this->options->tolerance);
        $this->messages = new LiveIndex($this->options->tolerance);
        $this->quotes = new LiveIndex(0);

        foreach ($this->live->iterateAssociative('SELECT id, topic_id, author_id, created_at, content, legacy_ref FROM ctso_comment') as $r) {
            $this->comments->add('c', (int) $r['id'], (int) $r['topic_id'], null !== $r['author_id'] ? (int) $r['author_id'] : null, self::wallClock($r['created_at']), (string) $r['content']);
            $this->rememberRef($r['legacy_ref']);
        }
        foreach ($this->live->iterateAssociative('SELECT id, author_id, recipient_id, created_at, content, legacy_ref FROM ctso_message') as $r) {
            $this->messages->add(self::messageGroup($r['author_id'], $r['recipient_id']), (int) $r['id'], null, null, self::wallClock($r['created_at']), (string) $r['content']);
            $this->rememberRef($r['legacy_ref']);
        }
        foreach ($this->live->iterateAssociative('SELECT id, content, legacy_ref FROM ctso_quote') as $r) {
            $this->quotes->add('q', (int) $r['id'], null, null, 0, (string) $r['content']);
            $this->rememberRef($r['legacy_ref']);
        }
        foreach ($this->live->iterateAssociative('SELECT id, title, created_at, legacy_ref FROM ctso_topic') as $r) {
            $this->topicsByTitle[self::normalizeTitle($r['title'])][] = ['id' => (int) $r['id'], 'ts' => self::wallClock($r['created_at'])];
            $this->rememberRef($r['legacy_ref']);
        }
    }

    private function rememberRef(?string $ref): void
    {
        if (null !== $ref) {
            $this->existingRefs[$ref] = true;
        }
    }

    /** Seconds since epoch of the *digits* of a stored DATETIME (Paris wall clock), read as if UTC. */
    private static function wallClock(string|\DateTimeInterface $date): int
    {
        $string = $date instanceof \DateTimeInterface ? $date->format('Y-m-d H:i:s') : $date;

        return (new \DateTimeImmutable($string, new \DateTimeZone('UTC')))->getTimestamp();
    }

    private static function messageGroup(int|string|null $author, int|string|null $recipient): string
    {
        return 'm:'.($author ?? 'x').'>'.($recipient ?? 'x');
    }

    private static function normalizeTitle(string $title): string
    {
        // The previous migration renamed the comment topics of albums ("Commentaires de l'album: X" => "X").
        $title = preg_replace('/^\s*commentaires de l.album\s*:\s*/iu', '', $title) ?? $title;

        return TextNormalizer::fingerprint($title);
    }

    // ------------------------------------------------------------------ platforms

    /**
     * @param list<LegacyTopic>                                     $topics
     * @param array<int, array{title: string, description: string}> $forums
     * @param list<LegacyMessage>                                   $messages
     */
    private function importPlatform(string $source, array $topics, array $forums, array $messages): void
    {
        foreach ($topics as $topic) {
            $this->importTopic($source, $topic, $forums);
        }
        $this->importMessages($source, $messages);
    }

    private function importPhpbb3(): void
    {
        $this->importPlatform('phpbb3', $this->phpbb3->topics(), $this->phpbb3->forums(), $this->phpbb3->messages());
        (new AttachmentImporter($this->live, $this->users, $this->report, $this->options, $this->photoPipeline))->import($this->phpbb3->attachments(), $this->postComments);
    }

    private function importOldPhp(): void
    {
        // Photos first: the posts embed them and their links are rewritten to the live photo ids.
        (new PhotoImporter($this->live, $this->oldPhp, $this->users, $this->report, $this->options, $this->photoLinks, $this->photoPipeline))->import();
        $this->importPlatform('oldphp', $this->oldPhp->topics(), [], $this->oldPhp->messages());
        $this->importQuotes();
    }

    /** @param array<int, array{title: string, description: string}> $forums */
    private function importTopic(string $source, LegacyTopic $topic, array $forums): void
    {
        $r = $this->report;
        $r->total($source, 'topics');

        $votes = [];
        /** @var list<LegacyPost> $pending */
        $pending = [];
        foreach ($topic->posts as $post) {
            $r->total($source, 'posts');
            if (null !== $post->skipReason) {
                $r->skipped($source, 'posts', $post->skipReason);
                continue;
            }
            $post = $post->withHtml($this->photoLinks->rewrite($post->html));
            if ('' === trim(strip_tags($post->html, '<img><iframe>'))) {
                $r->skipped($source, 'posts', 'empty after conversion');
                continue;
            }
            $author = $this->users->peek($source, $post->authorId, $post->guestName);
            $match = $this->comments->claim('c', self::wallClock($post->createdAt), $post->html, $author);
            if (null === $match) {
                $pending[] = $post;
                continue;
            }
            $r->present($source, 'posts');
            $this->postComments[$post->ref()] = $match->id;
            if (null !== $match->topicId) {
                $votes[$match->topicId] = ($votes[$match->topicId] ?? 0) + 1;
            }
            if (IndexMatch::NEAR === $match->kind && !$match->isSuspicious()) {
                $r->detail($source.' fuzzy matches accepted as already present', sprintf('%s = live comment #%d (similarity %.2f)', $post->ref(), $match->id, $match->similarity));
            }
            if ($match->isSuspicious()) {
                $r->suspicious(sprintf(
                    '%s matched live comment #%d (%s, similarity %.2f, Δt %ds%s)',
                    $post->ref(), $match->id, $match->kind, $match->similarity, $match->delta,
                    $match->authorMismatch ? ', DIFFERENT AUTHOR' : '',
                ));
            }
        }

        if ($votes) {
            arsort($votes);
            $targetId = array_key_first($votes);
            if (\count($votes) > 1) {
                $r->suspicious(sprintf('%s "%s": its messages are spread over live topics %s', $topic->ref(), $topic->title, implode(', ', array_map(static fn ($id, $n) => "#$id ($n)", array_keys($votes), $votes))));
            }
        } else {
            $targetId = $this->liveTopicByTitle($topic);
        }

        if (null !== $targetId) {
            $this->topicClaims[$targetId] = true;
            $r->present($source, 'topics');
        } elseif ($topic->posts && !$pending) {
            $r->skipped($source, 'topics', 'no importable message');

            return;
        } else {
            $forumId = $this->forums->forumFor($source, $topic->forumId, $topic->kind, $forums, $topic->createdAt);
            if (null === $forumId) {
                $r->skipped($source, 'topics', 'no live forum for legacy forum #'.$topic->forumId);
                foreach ($pending as $ignored) {
                    $r->skipped($source, 'posts', 'topic skipped (no live forum)');
                }

                return;
            }
            $targetId = $this->insertTopic($source, $topic, $forumId, $pending[0] ?? null);
            $r->imported($source, 'topics');
            $r->detail("$source topics imported", sprintf('%s "%s" (%s) → forum %s, topic #%d, %d message(s)', $topic->ref(), $topic->title, $topic->createdAt->format('Y-m-d'), $forumId, $targetId, \count($pending)));
        }

        foreach ($pending as $post) {
            if (isset($this->existingRefs[$post->ref()])) {
                $r->skipped($source, 'posts', 'legacy_ref already used by a live comment');
                continue;
            }
            $this->insertComment($source, $post, $targetId, $topic);
        }
    }

    private function liveTopicByTitle(LegacyTopic $topic): ?int
    {
        $ts = self::wallClock($topic->createdAt);
        foreach ($this->topicsByTitle[self::normalizeTitle($topic->title)] ?? [] as $candidate) {
            if (!isset($this->topicClaims[$candidate['id']]) && abs($candidate['ts'] - $ts) <= 2 * 86400) {
                $this->report->detail($topic->source.' topics matched by title and date', sprintf('%s "%s" = live topic #%d', $topic->ref(), $topic->title, $candidate['id']));

                return $candidate['id'];
            }
        }

        return null;
    }

    private function insertTopic(string $source, LegacyTopic $topic, string $forumId, ?LegacyPost $firstPending): int
    {
        $authorId = $this->users->resolve($source, $topic->authorId, $firstPending?->guestName ?? ($topic->posts[0]->guestName ?? null));
        $content = null !== $topic->summaryHtml ? $this->sanitizeTracked($topic->summaryHtml) : '';
        $created = LegacyDate::sql($topic->createdAt);
        $title = mb_substr($topic->title, 0, 128);
        if ('' === trim($title)) {
            $title = '(sans titre)';
        }

        $id = $this->insert('ctso_topic', [
            'title' => $title,
            'content' => '' !== $content ? $content : null,
            'created_at' => $created,
            'updated_at' => $created,
            'views' => $topic->views,
            'comment_count' => 0,
            'forum_id' => $forumId,
            'author_id' => $authorId,
            'last_activity_at' => $created,
            'legacy_ref' => $topic->ref(),
        ]);
        $this->topicsByTitle[self::normalizeTitle($title)][] = ['id' => $id, 'ts' => self::wallClock($topic->createdAt)];
        $this->topicClaims[$id] = true;
        $this->existingRefs[$topic->ref()] = true;
        $this->touchedTopics[$id] = true;

        return $id;
    }

    private function insertComment(string $source, LegacyPost $post, int $topicId, LegacyTopic $topic): void
    {
        $html = $this->sanitizeTracked($post->html);
        if ('' === trim(strip_tags($html, '<img><iframe>'))) {
            $this->report->skipped($source, 'posts', 'empty after sanitation');

            return;
        }
        $authorId = $this->users->resolve($source, $post->authorId, $post->guestName);
        $created = LegacyDate::sql($post->createdAt);
        $id = $this->insert('ctso_comment', [
            'content' => $html,
            'created_at' => $created,
            'updated_at' => $created,
            'topic_id' => $topicId,
            'author_id' => $authorId,
            'legacy_ref' => $post->ref(),
        ]);
        $this->comments->add('c', $id, $topicId, $authorId, self::wallClock($post->createdAt), $html, true);
        $this->postComments[$post->ref()] = $id;
        $this->existingRefs[$post->ref()] = true;
        $this->touchedTopics[$topicId] = true;
        $this->report->imported($source, 'posts');
        $this->report->detail("$source posts imported", sprintf('%s → topic #%d "%s" %s: %s', $post->ref(), $topicId, $topic->title, $post->createdAt->format('Y-m-d H:i'), mb_substr(trim(strip_tags($html)), 0, 70)));
    }

    // ------------------------------------------------------------------ private messages

    /** @param list<LegacyMessage> $messages */
    private function importMessages(string $source, array $messages): void
    {
        $r = $this->report;
        foreach ($messages as $message) {
            $r->total($source, 'private messages');
            if (null !== $message->skipReason) {
                $r->skipped($source, 'private messages', $message->skipReason);
                continue;
            }
            if ('' === trim(strip_tags($message->html, '<img><iframe>'))) {
                $r->skipped($source, 'private messages', 'empty after conversion');
                continue;
            }
            if (!$this->users->knows($source, $message->recipientId) || (null !== $message->authorId && !$this->users->knows($source, $message->authorId))) {
                $r->skipped($source, 'private messages', 'sender or recipient account does not exist in the dump');
                $r->detail($source.' private messages with unknown account', sprintf('%s from #%s to #%d: %s', $message->ref(), $message->authorId ?? '-', $message->recipientId, mb_substr(trim(strip_tags($message->html)), 0, 80)));
                continue;
            }
            $author = $this->users->peek($source, $message->authorId);
            $recipient = $this->users->peek($source, $message->recipientId);
            $match = null;
            if (null !== $author && null !== $recipient) {
                $group = self::messageGroup($author, $recipient);
                $ts = self::wallClock($message->createdAt);
                // Rows created by the previous migration hold the body alone, rows created by this command subject + body.
                $match = $this->messages->claim($group, $ts, $message->html, null)
                    ?? (null !== $message->subject ? $this->messages->claim($group, $ts, Phpbb2Source::withSubject($message->subject, $message->html), null) : null);
            }
            if (null !== $match) {
                $r->present($source, 'private messages');
                if ($match->isSuspicious()) {
                    $r->suspicious(sprintf('%s matched live message #%d (%s, similarity %.2f, Δt %ds)', $message->ref(), $match->id, $match->kind, $match->similarity, $match->delta));
                }
                continue;
            }
            if (isset($this->existingRefs[$message->ref()])) {
                $r->skipped($source, 'private messages', 'legacy_ref already used by a live message');
                $r->detail($source.' rows skipped only because their legacy_ref exists', $message->ref().': '.mb_substr(trim(strip_tags($message->html)), 0, 80));
                continue;
            }

            $html = $this->sanitizeTracked(Phpbb2Source::withSubject($message->subject, $message->html));
            $authorId = $this->users->resolve($source, $message->authorId);
            $recipientId = $this->users->resolve($source, $message->recipientId);
            $created = LegacyDate::sql($message->createdAt);
            $id = $this->insert('ctso_message', [
                'content' => $html,
                'state' => 4,
                'created_at' => $created,
                'updated_at' => $created,
                'author_id' => $authorId,
                'recipient_id' => $recipientId,
                'legacy_ref' => $message->ref(),
            ]);
            // Indexed on the body alone, like the rows loaded from the database would be compared.
            $this->messages->add(self::messageGroup($authorId, $recipientId), $id, null, null, self::wallClock($message->createdAt), $message->html, true);
            $this->existingRefs[$message->ref()] = true;
            $r->imported($source, 'private messages');
            $r->detail("$source private messages imported", sprintf('%s %s: %s', $message->ref(), $message->createdAt->format('Y-m-d H:i'), mb_substr(trim(strip_tags($html)), 0, 70)));
        }
    }

    // ------------------------------------------------------------------ quotes

    private function importQuotes(): void
    {
        $r = $this->report;
        $source = 'oldphp';
        foreach ($this->oldPhp->quotes() as $quote) {
            $r->total($source, 'quotes');
            if ('' === trim(strip_tags($quote->html))) {
                $r->skipped($source, 'quotes', 'empty');
                continue;
            }
            if (null !== $this->quotes->claim('q', 0, $quote->html, null)) {
                $r->present($source, 'quotes');
                continue;
            }
            if (isset($this->existingRefs[$quote->ref()])) {
                $r->skipped($source, 'quotes', 'legacy_ref already used');
                continue;
            }
            $html = $this->sanitizeTracked($quote->html);
            $now = LegacyDate::sql(new \DateTimeImmutable());
            $id = $this->insert('ctso_quote', [
                'content' => $html,
                'original_author' => mb_substr('' !== $quote->originalAuthor ? $quote->originalAuthor : 'Anonyme', 0, 128),
                'created_at' => $now,
                'updated_at' => $now,
                'author_id' => $quote->submitterId ? $this->users->resolve($source, $quote->submitterId) : null,
                'legacy_ref' => $quote->ref(),
            ]);
            $this->quotes->add('q', $id, null, null, 0, $html, true);
            $this->existingRefs[$quote->ref()] = true;
            $r->imported($source, 'quotes');
            $r->detail('oldphp quotes imported', sprintf('%s "%s" — %s', $quote->ref(), mb_substr(trim(strip_tags($html)), 0, 70), $quote->originalAuthor));
        }
        $r->note('no chat / shoutbox table exists in any of the three legacy dumps: nothing to import into ctso_chat_message');
    }

    // ------------------------------------------------------------------ plumbing

    private function sanitizeTracked(string $html): string
    {
        $clean = $this->sanitizer->sanitize($html);
        if (preg_match_all('~src="/smilies/([A-Za-z0-9_.-]+)"~', $clean, $m)) {
            foreach ($m[1] as $file) {
                $this->smilies[$file] = true;
            }
        }

        return $clean;
    }

    /** @param array<string, mixed> $data */
    private function insert(string $table, array $data): int
    {
        if ($this->options->dryRun) {
            return --$this->fakeId;
        }
        $this->live->insert($table, $data);

        return (int) $this->live->lastInsertId();
    }

    /** comment_count / last_activity_at of every topic that received comments (or was created). */
    private function recomputeTopics(): void
    {
        if (!$this->touchedTopics) {
            return;
        }
        $ids = implode(',', array_map('intval', array_keys($this->touchedTopics)));
        $this->live->executeStatement(
            "UPDATE ctso_topic t SET
                t.comment_count = (SELECT COUNT(*) FROM ctso_comment c WHERE c.topic_id = t.id),
                t.last_activity_at = COALESCE((SELECT MAX(c.created_at) FROM ctso_comment c WHERE c.topic_id = t.id), t.created_at)
             WHERE t.id IN ($ids)"
        );
    }

    /** Smilies referenced by imported content that the new forum does not serve yet are copied from the old trees. */
    private function installSmilies(): void
    {
        $dirs = array_filter([
            $this->options->oldPhpDir ? $this->options->oldPhpDir.'/images/smilies' : null,
            $this->options->phpbb3Dir ? $this->options->phpbb3Dir.'/phpBB3/images/smilies' : null,
        ]);
        foreach (array_keys($this->smilies) as $file) {
            $target = $this->options->smiliesDirectory.'/'.$file;
            if (is_file($target)) {
                continue;
            }
            $copied = false;
            foreach ($dirs as $dir) {
                if (is_file($dir.'/'.$file)) {
                    if (!$this->options->dryRun) {
                        copy($dir.'/'.$file, $target);
                    }
                    $this->report->note(sprintf('smiley %s %s from %s', $file, $this->options->dryRun ? 'would be copied' : 'copied', $dir));
                    $copied = true;
                    break;
                }
            }
            if (!$copied) {
                $this->report->note(sprintf('smiley %s is used by imported content but missing from public/smilies and from the old trees (mount them, see docs/legacy-import.md)', $file));
            }
        }
    }
}
