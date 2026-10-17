<?php

namespace App\Legacy\Import;

final readonly class ReimportOptions
{
    /**
     * @param list<string> $sources           platforms to process, in order (phpbb2, phpbb3, oldphp)
     * @param int          $tolerance         seconds of difference tolerated between two copies of a message
     * @param string|null  $oldPhpDir         checkout of the old PHP forum (photos/, images/smilies/), null = not mounted
     * @param string|null  $phpbb3Dir         checkout of the phpBB 3 install (phpBB3/images/smilies/)
     * @param string       $photosDirectory   private storage of the photo originals
     * @param string       $smiliesDirectory  public/smilies
     */
    public function __construct(
        public bool $dryRun,
        public array $sources,
        public int $tolerance,
        public ?string $oldPhpDir,
        public ?string $phpbb3Dir,
        public string $photosDirectory,
        public string $smiliesDirectory,
    ) {
    }
}
