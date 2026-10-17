<?php

namespace App\Legacy\Import;

use App\Legacy\Text\TextNormalizer;

/**
 * In-memory index of the messages already present in the live database (and, during a run, of the rows
 * imported so far) used to recognise duplicates by timestamp + normalised text.
 *
 * A live row can be claimed once per source ({@see resetClaims()}), so that two identical posts of a source
 * need two live rows to both count as "already present".
 */
final class LiveIndex
{
    private const SHIFT_TOLERANCE = 36 * 3600;

    /** @var list<array{id: int, topic: ?int, author: ?int, ts: int, fp: string, own: string, claimed: bool}> */
    private array $entries = [];
    /** @var array<string, list<int>> "group|minute" => entry indexes */
    private array $buckets = [];
    /** @var array<string, list<int>> "group|fingerprint" => entry indexes, for long messages shifted in time */
    private array $byFingerprint = [];

    /** @param int $tolerance seconds between the live and the legacy timestamp of the same message */
    public function __construct(private readonly int $tolerance = 300)
    {
    }

    /** @param bool $claimed rows imported during the current source start claimed: only the *next* source can match them */
    public function add(string $group, int $id, ?int $topicId, ?int $authorId, int $timestamp, string $html, bool $claimed = false): void
    {
        $fp = TextNormalizer::fingerprint($html);
        $this->entries[] = [
            'id' => $id, 'topic' => $topicId, 'author' => $authorId, 'ts' => $timestamp,
            'fp' => $fp, 'own' => TextNormalizer::ownFingerprint($html), 'claimed' => $claimed,
        ];
        $i = array_key_last($this->entries);
        $this->buckets[$group.'|'.intdiv($timestamp, 60)][] = $i;
        $this->byFingerprint[$group.'|'.$fp][] = $i;
    }

    public function size(): int
    {
        return \count($this->entries);
    }

    public function resetClaims(): void
    {
        foreach ($this->entries as &$entry) {
            $entry['claimed'] = false;
        }
        unset($entry);
    }

    /** Looks for the live row of a legacy message; the row found is claimed. */
    public function claim(string $group, int $timestamp, string $html, ?int $authorId): ?IndexMatch
    {
        $fp = TextNormalizer::fingerprint($html);
        $own = TextNormalizer::ownFingerprint($html);
        $best = null;

        for ($minute = intdiv($timestamp - $this->tolerance, 60); $minute <= intdiv($timestamp + $this->tolerance, 60); ++$minute) {
            foreach ($this->buckets[$group.'|'.$minute] ?? [] as $i) {
                $e = $this->entries[$i];
                $delta = abs($e['ts'] - $timestamp);
                if ($e['claimed'] || $delta > $this->tolerance) {
                    continue;
                }
                $sameAuthor = null === $authorId || null === $e['author'] || $authorId === $e['author'];
                $kind = null;
                $similarity = 0.0;
                if ($e['fp'] === $fp) {
                    // Very short texts ("lol", "ok") say little: also require the same author.
                    if (\strlen($fp) >= 10 || $sameAuthor) {
                        $kind = IndexMatch::EXACT;
                        $similarity = 1.0;
                    }
                } elseif (\strlen($own) >= 15 && $e['own'] === $own) {
                    $kind = IndexMatch::OWN_TEXT;
                    $similarity = 0.97;
                } elseif ($sameAuthor && max(\strlen($fp), \strlen($e['fp'])) >= 20) {
                    $similarity = TextNormalizer::similarity($e['fp'], $fp);
                    if ($similarity >= 0.85) {
                        $kind = IndexMatch::NEAR;
                    }
                }
                if (null === $kind) {
                    continue;
                }
                $rank = [IndexMatch::EXACT => 0, IndexMatch::OWN_TEXT => 1, IndexMatch::NEAR => 2][$kind];
                if (null === $best || [$rank, $delta] < [$best[0], $best[1]]) {
                    $best = [$rank, $delta, $i, $kind, $similarity];
                }
            }
        }

        if (null === $best && \strlen($fp) >= 30) {
            foreach ($this->byFingerprint[$group.'|'.$fp] ?? [] as $i) {
                $e = $this->entries[$i];
                $delta = abs($e['ts'] - $timestamp);
                if (!$e['claimed'] && $delta <= self::SHIFT_TOLERANCE && (null === $best || $delta < $best[1])) {
                    $best = [3, $delta, $i, IndexMatch::SHIFTED, 1.0];
                }
            }
        }

        if (null === $best) {
            return null;
        }
        [, $delta, $i, $kind, $similarity] = $best;
        $this->entries[$i]['claimed'] = true;
        $e = $this->entries[$i];

        return new IndexMatch($e['id'], $e['topic'], $kind, $similarity, $e['ts'] - $timestamp, null !== $authorId && null !== $e['author'] && $authorId !== $e['author']);
    }
}
