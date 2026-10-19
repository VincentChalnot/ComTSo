<?php

namespace App\Legacy\Import;

use App\Legacy\LegacyDate;
use App\Legacy\Model\LegacyUser;
use Doctrine\DBAL\Connection;

/**
 * Maps the members of every legacy platform onto live users.
 *
 * Identities are clustered: two accounts are the same person when they share a normalised username (case, accents
 * and punctuation ignored: "Shogûn" = "shogun", "nounou !" = "nounou") or an e-mail address, directly or through
 * the accounts of the other platforms. A few nicknames that changed between platforms are listed in {@see ALIASES}.
 * A cluster without live user gets a *disabled* user, created lazily the first time imported content needs it.
 */
final class UserMapper
{
    public const GUEST_REF = 'legacy:guest';
    public const GUEST_NAME = 'Invité';

    /**
     * Normalised legacy nickname => live username. Facts established from the dumps:
     *  - "Shogûn" (phpBB 2, mercierm@example.invalid) is "Le Kilt" on phpBB 3 (same e-mail);
     *  - "nounou !" (phpBB 2/3) is "Lea" (same e-mail up to case), "Jonas" is "Le Djo", "Hiko" is "rozenn" (same e-mails);
     *  - guests signing "Vince" / "Vincent C." are Vincent.
     */
    private const ALIASES = [
        'shogun' => 'Le Kilt',
        'nounou' => 'Lea',
        'jonas' => 'Le Djo',
        'hiko' => 'rozenn',
        'vince' => 'Vincent',
        'vincentc' => 'Vincent',
    ];

    /** Platform whose user ids are the reference when another platform lost a user row. */
    private const ID_FALLBACK = ['oldphp' => 'phpbb3'];

    /** @var array<int, array{username: string, email: string, legacy_ref: ?string}> */
    private array $liveUsers = [];
    /** @var array<string, LegacyUser> "source:id" => user */
    private array $legacy = [];
    /** @var array<string, string> union-find parents */
    private array $parent = [];
    /** @var array<string, list<int>> cluster root => live user ids */
    private array $clusterLive = [];
    /** @var array<string, list<LegacyUser>> cluster root => legacy members */
    private array $clusterMembers = [];
    /** @var array<string, int> cluster root => created (or planned) live user id */
    private array $created = [];
    /** @var array<string, int> normalised name => live user id, for guest names */
    private array $nameToLive = [];
    private bool $built = false;
    private int $fakeId = 0;
    private ?int $guestId = null;
    /** @var list<string> */
    private array $createdLog = [];

    public function __construct(private readonly Connection $live, private readonly bool $dryRun)
    {
        foreach ($this->live->fetchAllAssociative('SELECT id, username, email, legacy_ref FROM fos_user') as $r) {
            $this->liveUsers[(int) $r['id']] = ['username' => $r['username'], 'email' => $r['email'], 'legacy_ref' => $r['legacy_ref']];
            if (self::GUEST_REF === $r['legacy_ref']) {
                $this->guestId = (int) $r['id'];
            }
        }
    }

    /** @param list<LegacyUser> $users */
    public function register(array $users): void
    {
        foreach ($users as $user) {
            $this->legacy[$user->source.':'.$user->id] = $user;
        }
        $this->built = false;
    }

    /** @return list<string> human readable list of the users created (or that would be created) */
    public function createdLog(): array
    {
        return $this->createdLog;
    }

    /** @return list<string> "source:user:id name => live user" for every legacy account (for the report) */
    public function mappingLog(): array
    {
        $this->build();
        $lines = [];
        foreach ($this->legacy as $user) {
            $root = $this->find($user->source.':'.$user->id);
            $id = $this->liveIdOf($root) ?? ($this->created[$root] ?? null);
            $lines[] = sprintf('%s "%s" => %s', $user->ref(), $user->username, null !== $id ? sprintf('#%d %s%s', $id, $this->liveUsers[$id]['username'], $id < 0 || str_ends_with((string) $this->liveUsers[$id]['email'], '@legacy.invalid') ? ' (created, disabled)' : '') : 'no live user (created only if content needs it)');
        }

        return $lines;
    }

    public static function normalize(string $name): string
    {
        static $transliterator = null;
        $transliterator ??= \Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
        $ascii = $transliterator ? $transliterator->transliterate($name) : mb_strtolower($name);

        return preg_replace('/[^a-z0-9]+/', '', (string) $ascii) ?? '';
    }

    /**
     * Live user id of a legacy account, *without* creating anything: null when the account has no live
     * counterpart yet (it would be created) or is unknown.
     */
    public function peek(string $source, ?int $userId, ?string $guestName = null): ?int
    {
        $this->build();
        if (null === $userId) {
            return $this->guestLive($guestName);
        }
        $root = $this->rootOf($source, $userId);
        if (null === $root) {
            return null;
        }

        return $this->liveIdOf($root) ?? ($this->created[$root] ?? null);
    }

    /** Whether the account exists in the dump of its platform (or in phpBB 3, for the old PHP forum). */
    public function knows(string $source, ?int $userId): bool
    {
        $this->build();

        return null !== $userId && null !== $this->rootOf($source, $userId);
    }

    /** Live user id, created if needed (a disabled user). Never null: unknown accounts and guests fall back to "Invité". */
    public function resolve(string $source, ?int $userId, ?string $guestName = null): int
    {
        $this->build();
        if (null !== $userId && null !== ($root = $this->rootOf($source, $userId))) {
            return $this->liveIdOf($root) ?? ($this->created[$root] ??= $this->createCluster($root));
        }
        $guest = null === $userId ? $this->guestLive($guestName) : null;

        return $guest ?? $this->guestUser();
    }

    // ------------------------------------------------------------------ clustering

    private function build(): void
    {
        if ($this->built) {
            return;
        }
        $this->parent = [];
        $this->clusterLive = $this->clusterMembers = $this->nameToLive = [];

        foreach ($this->liveUsers as $id => $u) {
            if (self::GUEST_REF === $u['legacy_ref']) {
                continue;
            }
            $this->union('live:'.$id, 'n:'.self::normalize($u['username']));
            $this->union('live:'.$id, 'e:'.mb_strtolower($u['email']));
        }
        foreach ($this->legacy as $key => $u) {
            $name = self::normalize($u->username);
            $this->union($key, 'n:'.$name);
            if (isset(self::ALIASES[$name])) {
                $this->union($key, 'n:'.self::normalize(self::ALIASES[$name]));
            }
            if ($u->email) {
                $this->union($key, 'e:'.mb_strtolower($u->email));
            }
        }
        foreach (array_keys($this->liveUsers) as $id) {
            $this->clusterLive[$this->find('live:'.$id)][] = $id;
        }
        foreach ($this->legacy as $key => $u) {
            $this->clusterMembers[$this->find($key)][] = $u;
        }
        foreach (array_keys($this->parent) as $node) {
            if (str_starts_with($node, 'n:') && isset($this->clusterLive[$this->find($node)])) {
                $this->nameToLive[substr($node, 2)] = $this->clusterLive[$this->find($node)][0];
            }
        }
        $this->built = true;
    }

    private function rootOf(string $source, int $userId): ?string
    {
        $key = $source.':'.$userId;
        if (!isset($this->legacy[$key]) && isset(self::ID_FALLBACK[$source])) {
            $key = self::ID_FALLBACK[$source].':'.$userId;
        }

        return isset($this->legacy[$key]) ? $this->find($key) : null;
    }

    /** Live user of a cluster, preferring the one whose username equals a legacy nickname. */
    private function liveIdOf(string $root): ?int
    {
        $ids = $this->clusterLive[$root] ?? [];
        if (!$ids) {
            return null;
        }
        $names = array_map(static fn (LegacyUser $u): string => self::normalize($u->username), $this->clusterMembers[$root] ?? []);
        foreach ($ids as $id) {
            if (\in_array(self::normalize($this->liveUsers[$id]['username']), $names, true)) {
                return $id;
            }
        }

        return $ids[0];
    }

    private function guestLive(?string $name): ?int
    {
        if (null === $name) {
            return null;
        }
        $this->build();
        $norm = self::normalize(preg_replace('/\(.*?\)/', '', $name) ?? $name);
        if ('' === $norm) {
            return null;
        }
        if (isset(self::ALIASES[$norm])) {
            $norm = self::normalize(self::ALIASES[$norm]);
        }
        if (isset($this->nameToLive[$norm])) {
            return $this->nameToLive[$norm];
        }
        foreach ($this->nameToLive as $known => $id) {
            if (\strlen($known) >= 4 && str_starts_with($norm, (string) $known)) {
                return $id;
            }
        }

        return null;
    }

    private function find(string $node): string
    {
        $this->parent[$node] ??= $node;
        while ($this->parent[$node] !== $node) {
            $this->parent[$node] = $this->parent[$this->parent[$node]];
            $node = $this->parent[$node];
        }

        return $node;
    }

    private function union(string $a, string $b): void
    {
        if ('n:' === $b || 'e:' === $b) {
            return;
        }
        $this->parent[$this->find($a)] = $this->find($b);
    }

    // ------------------------------------------------------------------ creation

    private function createCluster(string $root): int
    {
        $members = $this->clusterMembers[$root];
        // Most informative platform first: phpBB 3 (latest accounts), then the old PHP forum, then phpBB 2.
        usort($members, static fn (LegacyUser $a, LegacyUser $b): int => array_search($a->source, ['phpbb3', 'oldphp', 'phpbb2'], true) <=> array_search($b->source, ['phpbb3', 'oldphp', 'phpbb2'], true));
        $primary = $members[0];
        $registered = null;
        foreach ($members as $m) {
            if ($m->registeredAt && (null === $registered || $m->registeredAt < $registered)) {
                $registered = $m->registeredAt;
            }
        }

        $username = $primary->username;
        while ($this->usernameTaken($username)) {
            $username .= ' (ancien forum)';
        }
        $this->createdLog[] = sprintf('%s (%s)', $username, implode(', ', array_map(static fn (LegacyUser $m): string => $m->ref(), $members)));

        return $this->insertDisabled($username, $this->emailOf($members) ?? self::normalize($username).'@legacy.invalid', $primary->ref(), $registered ?? new \DateTimeImmutable('2007-06-23 00:00:00'));
    }

    private function guestUser(): int
    {
        if (null !== $this->guestId) {
            return $this->guestId;
        }
        $username = self::GUEST_NAME;
        while ($this->usernameTaken($username)) {
            $username .= ' (ancien forum)';
        }
        $this->createdLog[] = sprintf('%s (anonymous posts of the old platforms)', $username);

        return $this->guestId = $this->insertDisabled($username, 'guest@legacy.invalid', self::GUEST_REF, new \DateTimeImmutable('2004-04-28 19:49:59'));
    }

    /**
     * The real address of the account when a platform knew it (the account stays disabled, it only keeps the member's identity);
     * null when none is usable: unknown, malformed, or already the address of another account (e-mails are unique).
     *
     * @param list<LegacyUser> $members
     */
    private function emailOf(array $members): ?string
    {
        $taken = array_map(static fn (array $u): string => mb_strtolower((string) $u['email']), $this->liveUsers);
        foreach ($members as $member) {
            $email = trim((string) $member->email);
            if ('' !== $email && false !== filter_var($email, \FILTER_VALIDATE_EMAIL) && !str_ends_with(mb_strtolower($email), '@legacy.invalid') && !\in_array(mb_strtolower($email), $taken, true)) {
                return $email;
            }
        }

        return null;
    }

    private function usernameTaken(string $username): bool
    {
        foreach ($this->liveUsers as $u) {
            if (self::normalize($u['username']) === self::normalize($username)) {
                return true;
            }
        }

        return false;
    }

    private function insertDisabled(string $username, string $email, string $ref, \DateTimeInterface $registeredAt): int
    {
        if ($this->dryRun) {
            $id = --$this->fakeId;
        } else {
            $this->live->insert('fos_user', [
                'username' => $username,
                'email' => $email,
                'roles' => '[]',
                // Not a valid hash of any password: the account cannot be logged into (it is disabled anyway).
                'password' => '!'.bin2hex(random_bytes(32)),
                'enabled' => 0,
                'registered_at' => LegacyDate::sql($registeredAt),
                'chat_last_read_id' => 0,
                'legacy_ref' => $ref,
            ]);
            $id = (int) $this->live->lastInsertId();
        }
        $this->liveUsers[$id] = ['username' => $username, 'email' => $email, 'legacy_ref' => $ref];

        return $id;
    }
}
