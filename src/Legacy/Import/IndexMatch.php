<?php

namespace App\Legacy\Import;

/** Result of {@see LiveIndex::claim()}: which live row a legacy message corresponds to, and how sure we are. */
final readonly class IndexMatch
{
    /** Same visible text, same time. */
    public const EXACT = 'exact';
    /** Same text outside quotes (the previous migration rewrote quote blocks). */
    public const OWN_TEXT = 'own-text';
    /** Same author, same time, text >= 85% similar: reported for review. */
    public const NEAR = 'near-duplicate';
    /** Same long text, timestamp off by up to 36 h: reported for review. */
    public const SHIFTED = 'time-shifted';

    public function __construct(
        public int $id,
        public ?int $topicId,
        public string $kind,
        public float $similarity,
        /** live timestamp - legacy timestamp, seconds */
        public int $delta,
        public bool $authorMismatch,
    ) {
    }

    /**
     * Matches worth showing to the owner. A near match with the same author, the same second and >= 90% identical
     * text is the usual fingerprint of the previous migration (stray "\n", dropped images…) and is not.
     */
    public function isSuspicious(): bool
    {
        return self::SHIFTED === $this->kind
            || $this->authorMismatch
            || (self::NEAR === $this->kind && ($this->similarity < 0.9 || abs($this->delta) > 120));
    }
}
