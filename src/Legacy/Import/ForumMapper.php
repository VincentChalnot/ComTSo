<?php

namespace App\Legacy\Import;

use App\Legacy\LegacyDate;
use Doctrine\DBAL\Connection;

/**
 * Old forums => live forums (ids are slugs).
 *
 *  - phpBB 3 (2007-06 → 2009-06) had 3 forums that the Symfony 2 migration all merged into `archives`; same here.
 *  - The old PHP forum numbered its forums 0-3 (the same four the live site has): 0 Bac à Sable, 1 Organisation,
 *    2 Dr Schtroumph, 3 Archives; its album topics live in `albums`.
 *  - phpBB 2 (2004-04 → 2007-06) predates every live forum. The owner wants those conversations back under
 *    their original forum names, so each old forum becomes a new live forum, except "Le cabinet du Dr
 *    Schtroumpf", which is the ancestor of the live `cabinet-du-dr-schtroumph` and joins it.
 */
final class ForumMapper
{
    /** phpBB 2 forum id => [live forum id, order]; the title comes from the dump unless the forum exists. */
    private const PHPBB2 = [
        2 => ['bienvenue', 10],
        11 => ['conseil-des-ministres', 11],
        12 => ['cabinet-du-dr-schtroumph', null],
        13 => ['salle-a-tous-le-monde', 12],
        14 => ['ballasts', 13],
        15 => ['journal-de-production', 14],
        16 => ['traduction-de-tsbd', 15],
        17 => ['un-cadavre-dans-un-sac-a-main', 16],
    ];
    private const PHPBB3 = [3 => 'archives', 4 => 'archives', 5 => 'archives'];
    private const OLDPHP = [0 => 'le-bac-a-sable', 1 => 'organisation', 2 => 'cabinet-du-dr-schtroumph', 3 => 'archives'];

    /** @var array<string, true> */
    private array $known = [];
    /** @var list<string> */
    private array $createdLog = [];

    public function __construct(private readonly Connection $live, private readonly bool $dryRun)
    {
        foreach ($this->live->fetchFirstColumn('SELECT id FROM ctso_forum') as $id) {
            $this->known[$id] = true;
        }
    }

    /** @return list<string> */
    public function createdLog(): array
    {
        return $this->createdLog;
    }

    /**
     * @param array<int, array{title: string, description: string}> $sourceForums forums of the platform, from its dump
     *
     * @return string|null live forum id, null when the old forum has no equivalent (the topic is skipped)
     */
    public function forumFor(string $source, int $forumId, ?string $kind, array $sourceForums, \DateTimeInterface $firstActivity): ?string
    {
        switch ($source) {
            case 'phpbb3':
                return self::PHPBB3[$forumId] ?? null;
            case 'oldphp':
                return 'album' === $kind ? 'albums' : (self::OLDPHP[$forumId] ?? null);
            case 'phpbb2':
                if (!isset(self::PHPBB2[$forumId])) {
                    return null;
                }
                [$id, $order] = self::PHPBB2[$forumId];
                if (!isset($this->known[$id])) {
                    $title = $sourceForums[$forumId]['title'] ?? $id;
                    $this->createdLog[] = sprintf('%s "%s"', $id, $title);
                    if (!$this->dryRun) {
                        $this->live->insert('ctso_forum', [
                            'id' => $id,
                            'title' => mb_substr($title, 0, 128),
                            'content' => '' !== ($sourceForums[$forumId]['description'] ?? '') ? htmlspecialchars($sourceForums[$forumId]['description'], \ENT_NOQUOTES) : null,
                            'ord' => $order,
                            'created_at' => LegacyDate::sql($firstActivity),
                            'updated_at' => LegacyDate::sql($firstActivity),
                        ]);
                    }
                    $this->known[$id] = true;
                }

                return $id;
        }

        return null;
    }
}
