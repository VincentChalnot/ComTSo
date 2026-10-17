<?php

namespace App\Command;

use App\Content\ContentSanitizer;
use App\Legacy\Import\ImportReport;
use App\Legacy\Import\ReimportOptions;
use App\Legacy\Import\Reimporter;
use App\Legacy\LegacyConnections;
use App\Legacy\Source\OldPhpSource;
use App\Legacy\Source\Phpbb2Source;
use App\Legacy\Source\Phpbb3Source;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * SINGLE-USE command: recovers the messages of the platforms older than the Symfony 2 forum (old PHP forum,
 * phpBB 2, phpBB 3) that the previous migration missed. See docs/legacy-import.md.
 *
 * Needs the temporary legacy databases: `docker compose --profile legacy up -d legacy-mysql legacy-postgres`.
 */
#[AsCommand(
    name: 'app:legacy:reimport',
    description: 'One-shot: recovers the messages of the old platforms (phpBB 2/3, old PHP forum) missing from the live database',
)]
final class ReimportLegacyCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ContentSanitizer $sanitizer,
        #[Autowire(env: 'default::LEGACY_OLDPHP_URL')]
        private readonly ?string $oldPhpUrl,
        #[Autowire(env: 'default::LEGACY_PHPBB3_URL')]
        private readonly ?string $phpbb3Url,
        #[Autowire('%photos_directory%')]
        private readonly string $photosDirectory,
        #[Autowire('%kernel.project_dir%/public/smilies')]
        private readonly string $smiliesDirectory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report what would be imported; writes nothing')
            ->addOption('source', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only process this platform: '.implode(', ', Reimporter::SOURCES).' (repeatable)')
            ->addOption('tolerance', null, InputOption::VALUE_REQUIRED, 'Seconds of difference tolerated between the legacy and the live timestamp of the same message', '300')
            ->addOption('oldphp-dir', null, InputOption::VALUE_REQUIRED, 'Checkout of the old PHP forum (photos/, images/smilies/); photos are skipped when absent', '/legacy/comtso.old')
            ->addOption('phpbb3-dir', null, InputOption::VALUE_REQUIRED, 'Checkout of the phpBB 3 install (phpBB3/images/smilies/)', '/legacy/comtso2.old')
            ->setHelp('Use -v to list every imported row, -vv also the topics matched by title.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $sources = $input->getOption('source') ?: Reimporter::SOURCES;
        if ($unknown = array_diff($sources, Reimporter::SOURCES)) {
            $io->error('Unknown source(s): '.implode(', ', $unknown).'. Expected: '.implode(', ', Reimporter::SOURCES));

            return Command::INVALID;
        }
        if (!$this->oldPhpUrl || !$this->phpbb3Url) {
            $io->error('LEGACY_OLDPHP_URL / LEGACY_PHPBB3_URL are not set (see .env).');

            return Command::FAILURE;
        }

        try {
            $oldPhpDb = LegacyConnections::open($this->oldPhpUrl);
            $phpbb3Db = LegacyConnections::open($this->phpbb3Url);
            $oldPhpDb->executeQuery('SELECT 1 FROM phpbb_posts LIMIT 1');
            $phpbb3Db->executeQuery('SELECT 1 FROM phpbb3_posts LIMIT 1');
        } catch (\Throwable $e) {
            $io->error('Cannot read the legacy databases: '.$e->getMessage());
            $io->writeln('Start them with: docker compose --profile legacy up -d legacy-mysql legacy-postgres');

            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $oldPhpDir = $this->existingDir($input->getOption('oldphp-dir'));
        $phpbb3Dir = $this->existingDir($input->getOption('phpbb3-dir'));
        $io->title($dryRun ? 'Legacy reimport — DRY RUN (nothing is written)' : 'Legacy reimport — WRITING to the live database');
        $io->writeln(sprintf(
            'Sources: %s. Old PHP forum files: %s. phpBB 3 files: %s.',
            implode(', ', $sources),
            $oldPhpDir ?? 'not mounted (photos / smilies cannot be copied)',
            $phpbb3Dir ?? 'not mounted',
        ));

        $reimporter = new Reimporter($this->connection, $this->sanitizer, new Phpbb2Source($oldPhpDb), new Phpbb3Source($phpbb3Db), new OldPhpSource($oldPhpDb));
        $report = $reimporter->run(new ReimportOptions(
            $dryRun,
            array_values($sources),
            (int) $input->getOption('tolerance'),
            $oldPhpDir,
            $phpbb3Dir,
            $this->photosDirectory,
            $this->smiliesDirectory,
        ));

        $this->render($io, $report, $output->isVerbose(), $output->isVeryVerbose());
        $io->success($dryRun ? 'Dry run finished, the database was not modified.' : 'Import finished.');

        return Command::SUCCESS;
    }

    private function existingDir(?string $dir): ?string
    {
        return null !== $dir && is_dir($dir) ? rtrim($dir, '/') : null;
    }

    private function render(SymfonyStyle $io, ImportReport $report, bool $verbose, bool $veryVerbose): void
    {
        foreach ($report->rows() as $source => $tables) {
            $io->section($source);
            $rows = [];
            foreach ($tables as $table => $row) {
                $skipped = array_sum($row['skipped']);
                $reasons = [];
                foreach ($row['skipped'] as $reason => $n) {
                    $reasons[] = "$n × $reason";
                }
                $rows[] = [$table, $row['total'], $row['present'], $row['imported'], $skipped, implode("\n", $reasons)];
            }
            $io->table(['table', 'total', 'already present', 'imported', 'skipped', 'skipped because'], $rows);
        }

        if ($report->notes()) {
            $io->section('Notes');
            $io->listing($report->notes());
        }

        $io->section('Suspicious matches (treated as already present — please review)');
        $io->writeln($report->suspiciousLines() ? '' : 'none');
        if ($report->suspiciousLines()) {
            $io->listing($report->suspiciousLines());
        }

        if ($verbose) {
            foreach ($report->details() as $group => $lines) {
                if (!$veryVerbose && str_contains($group, 'matched by title')) {
                    continue;
                }
                $io->section(sprintf('%s (%d)', $group, \count($lines)));
                $io->listing($lines);
            }
        }
    }
}
