<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Migrates the data of the legacy (Symfony 2 / FOSUserBundle) ComTSo database into the current schema.
 *
 * The legacy dump must first be loaded into a side database living on the same server, e.g.:
 *
 *   zcat var/2610110300.sql.gz | mariadb comtso_legacy
 *   bin/console app:legacy:import --source=comtso_legacy
 *
 * The user used by the application must be able to read the source database.
 */
#[AsCommand(
    name: 'app:legacy:import',
    description: 'Imports the legacy database and relinks the uploaded photos',
)]
class ImportLegacyCommand extends Command
{
    /** Tables emptied before an import, children first. */
    private const TARGET_TABLES = [
        'ctso_starred_topic', 'ctso_photo_topic', 'ctso_comment', 'ctso_topic', 'ctso_forum',
        'ctso_message', 'ctso_chat_message', 'ctso_quote', 'fos_user', 'ctso_photo',
    ];

    private const MIME_TYPES = ['jpeg' => 'image/jpeg', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];

    public function __construct(
        private readonly Connection $connection,
        private readonly Filesystem $filesystem,
        #[Autowire('%photos_directory%')]
        private readonly string $photosDirectory,
        #[Autowire('%kernel.project_dir%/var/photos/originals')]
        private readonly string $defaultPhotosSource,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Name of the database containing the legacy dump', 'comtso_legacy')
            ->addOption('photos-source', null, InputOption::VALUE_REQUIRED, 'Directory containing the legacy photo files', $this->defaultPhotosSource)
            ->addOption('copy-photos', null, InputOption::VALUE_NONE, 'Copy the photo files instead of moving them')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Wipe the content tables of the target database first');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $src = '`'.str_replace('`', '', $input->getOption('source')).'`';

        try {
            $this->connection->executeQuery("SELECT 1 FROM $src.fos_user LIMIT 1");
        } catch (\Throwable $e) {
            $io->error(sprintf('Cannot read the legacy database %s: %s', $src, $e->getMessage()));

            return Command::FAILURE;
        }

        $existing = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fos_user');
        if ($existing > 0 && !$input->getOption('force')) {
            $io->error('The target database already contains users. Use --force to wipe it before importing.');

            return Command::FAILURE;
        }

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            // DDL implicitly commits, so wipe the tables before opening the transaction.
            foreach (self::TARGET_TABLES as $table) {
                $this->connection->executeStatement("DELETE FROM $table");
                $this->connection->executeStatement("ALTER TABLE $table AUTO_INCREMENT = 1");
            }
            $this->connection->beginTransaction();

            $io->section('Database');
            $this->importPhotos($src, $io);
            $this->importUsers($src, $io);
            $this->importSimpleTables($src, $io);
            $this->connection->commit();
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $io->error($e->getMessage());

            return Command::FAILURE;
        } finally {
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }

        $io->section('Photo files');
        $this->relinkPhotoFiles($input->getOption('photos-source'), (bool) $input->getOption('copy-photos'), $io);

        $io->success('Legacy data imported.');

        return Command::SUCCESS;
    }

    private function importUsers(string $src, SymfonyStyle $io): void
    {
        $rows = $this->connection->fetchAllAssociative("SELECT * FROM $src.fos_user");
        foreach ($rows as $row) {
            $this->connection->insert('fos_user', [
                'id' => $row['id'],
                'username' => $row['username'],
                'email' => $row['email'],
                'enabled' => (int) ($row['enabled'] && !$row['locked'] && !$row['expired']),
                'salt' => $row['salt'] ?: null,
                'password' => $row['password'],
                'roles' => json_encode($this->unserializeArray($row['roles'])),
                'name' => $row['name'] ?: null,
                'surname' => $row['surname'] ?: null,
                'birthday' => $row['birthday'],
                'address' => $row['address'] ?: null,
                'phone' => $row['phone'] ?: null,
                'activities' => $this->rewriteContent($row['activities']),
                'signature' => $this->rewriteContent($row['signature']),
                'website' => $row['website'] ?: null,
                'registered_at' => $row['registered_at'],
                'previous_login' => $row['previous_login'],
                'last_activity' => $row['last_activity'],
                'last_login' => $row['last_login'],
                'avatar_id' => $row['avatar_id'],
                'config' => $this->normalizeJson($row['config']),
            ]);
        }
        $io->writeln(sprintf('  fos_user: %d rows', count($rows)));
    }

    private function importPhotos(string $src, SymfonyStyle $io): void
    {
        $rows = $this->connection->fetchAllAssociative("SELECT * FROM $src.ctso_photo");
        foreach ($rows as $row) {
            $exif = $this->normalizeExif($row['jsonExif'], $row['exif']);
            $extension = strtolower(pathinfo($row['filename'], PATHINFO_EXTENSION));
            $this->connection->insert('ctso_photo', [
                'id' => $row['id'],
                'author_id' => $row['author_id'],
                'filename' => $row['filename'],
                'original_filename' => $row['original_filename'],
                'file_size' => $row['fileSize'],
                'file_type' => self::MIME_TYPES[strtolower($row['fileType'])] ?? self::MIME_TYPES[$extension] ?? $row['fileType'],
                'file_modified_at' => $row['file_modified_at'],
                'taken_at' => $row['taken_at'],
                'width' => $row['width'],
                'height' => $row['height'],
                'exif' => $exif ? json_encode($exif, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'title' => $row['title'] !== '' ? $row['title'] : null,
                'content' => $this->rewriteContent($row['content']),
            ]);
        }
        $io->writeln(sprintf('  ctso_photo: %d rows', count($rows)));
    }

    /** Tables whose columns map 1:1, only the legacy HTML content needs its asset paths rewritten. */
    private function importSimpleTables(string $src, SymfonyStyle $io): void
    {
        $content = fn (string $column) => $this->contentSql($column);
        $tables = [
            'ctso_forum' => ['id', 'author_id', 'ord', 'created_at', 'updated_at', 'title', 'content*'],
            'ctso_topic' => ['id', 'forum_id', 'author_id', 'views', 'comment_count', 'created_at', 'updated_at', 'title', 'content*'],
            'ctso_comment' => ['id', 'topic_id', 'author_id', 'created_at', 'updated_at', 'content*'],
            'ctso_photo_topic' => ['id', 'topic_id', 'photo_id', 'author_id', 'ord', 'created_at', 'updated_at'],
            'ctso_message' => ['id', 'recipient_id', 'author_id', 'state', 'created_at', 'updated_at', 'content*'],
            'ctso_chat_message' => ['id', 'author_id', 'created_at', 'updated_at', 'content*'],
            'ctso_quote' => ['id', 'author_id', 'original_author', 'created_at', 'updated_at', 'content*'],
            'ctso_starred_topic' => ['user_id', 'topic_id'],
        ];

        foreach ($tables as $table => $columns) {
            $names = array_map(fn ($c) => rtrim($c, '*'), $columns);
            $selects = array_map(fn ($c) => str_ends_with($c, '*') ? $content(rtrim($c, '*')) : $c, $columns);
            $count = $this->connection->executeStatement(sprintf(
                'INSERT INTO %s (%s) SELECT %s FROM %s.%s',
                $table,
                implode(', ', $names),
                implode(', ', $selects),
                $src,
                $table,
            ));
            $io->writeln(sprintf('  %s: %d rows', $table, $count));
        }

        // The legacy denormalized counters drifted from reality: recompute them.
        $fixed = $this->connection->executeStatement(
            'UPDATE ctso_topic t SET t.comment_count = (SELECT COUNT(*) FROM ctso_comment c WHERE c.topic_id = t.id)
             WHERE t.comment_count <> (SELECT COUNT(*) FROM ctso_comment c WHERE c.topic_id = t.id)'
        );
        $io->writeln(sprintf('  ctso_topic.comment_count: %d stale counter(s) recomputed', $fixed));
    }

    private function relinkPhotoFiles(string $source, bool $copy, SymfonyStyle $io): void
    {
        if (!is_dir($source)) {
            $io->warning(sprintf('Photo source directory %s does not exist, files not relinked.', $source));

            return;
        }

        $this->filesystem->mkdir($this->photosDirectory);
        $filenames = $this->connection->fetchFirstColumn('SELECT filename FROM ctso_photo');
        $done = $already = 0;
        $missing = [];

        foreach ($filenames as $filename) {
            $from = $source.'/'.$filename;
            $to = $this->photosDirectory.'/'.$filename;
            if (is_file($to)) {
                ++$already;
                continue;
            }
            if (!is_file($from)) {
                $missing[] = $filename;
                continue;
            }
            $this->transfer($from, $to, $copy);
            ++$done;
        }

        $io->writeln(sprintf('  %d file(s) %s to %s, %d already in place', $done, $copy ? 'copied' : 'moved', $this->photosDirectory, $already));
        if ($missing) {
            $io->warning(sprintf('%d photo row(s) have no file: %s', count($missing), implode(', ', array_slice($missing, 0, 10))));
        }

        $known = array_flip($filenames);
        $orphans = array_filter(scandir($source), fn ($f) => is_file($source.'/'.$f) && !isset($known[$f]));
        if ($orphans) {
            $io->note(sprintf('%d file(s) in %s are not referenced by any photo row and were left untouched: %s', count($orphans), $source, implode(', ', $orphans)));
        }
    }

    private function transfer(string $from, string $to, bool $copy): void
    {
        if ($copy) {
            $this->filesystem->copy($from, $to);

            return;
        }
        try {
            $this->filesystem->rename($from, $to);
        } catch (\Throwable) {
            // Different filesystems/mounts: rename(2) is not possible.
            $this->filesystem->copy($from, $to);
            $this->filesystem->remove($from);
        }
    }

    /** SQL expression rewriting asset paths of the legacy HTML content. */
    private function contentSql(string $column): string
    {
        return sprintf("REPLACE(%s, '/images/smilies/', '/smilies/')", $column);
    }

    private function rewriteContent(?string $content): ?string
    {
        return $content === null || $content === '' ? null : str_replace('/images/smilies/', '/smilies/', $content);
    }

    private function unserializeArray(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $data = @unserialize($value, ['allowed_classes' => false]);

        return is_array($data) ? array_values($data) : [];
    }

    private function normalizeJson(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $data = json_decode($value, true);

        return is_array($data) ? json_encode($data) : null;
    }

    /**
     * Legacy rows store the raw ExifTool dump (PHP-serialized in `exif`, JSON in `jsonExif`).
     * Expose the keys used by the new UI and keep the raw dump under "ExifTool".
     */
    private function normalizeExif(?string $json, ?string $serialized): ?array
    {
        $raw = null;
        if ($json) {
            $raw = json_decode($json, true);
        }
        if (!is_array($raw) && $serialized) {
            $raw = @unserialize($serialized, ['allowed_classes' => false]);
        }
        if (!is_array($raw) || !$raw) {
            return null;
        }

        $pick = static function (string ...$keys) use ($raw) {
            foreach ($keys as $key) {
                if (isset($raw[$key]) && $raw[$key] !== '') {
                    return $raw[$key];
                }
            }

            return null;
        };

        $exif = array_filter([
            'Make' => $pick('IFD0:Make'),
            'Model' => $pick('IFD0:Model'),
            'DateTime' => $pick('IFD0:ModifyDate'),
            'ExposureTime' => $pick('ExifIFD:ExposureTime'),
            'FNumber' => $pick('ExifIFD:FNumber'),
            'ISOSpeedRatings' => $pick('ExifIFD:ISO'),
            'FocalLength' => ($focal = $pick('ExifIFD:FocalLength')) !== null ? preg_replace('/\s*mm.*$/', '', (string) $focal) : null,
            'DateTimeOriginal' => $pick('ExifIFD:DateTimeOriginal'),
        ], static fn ($v) => $v !== null);
        $exif['ExifTool'] = $raw;

        return $exif;
    }
}
