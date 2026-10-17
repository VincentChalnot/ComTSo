<?php

namespace App\Command;

use App\Repository\PhotoRepository;
use App\Service\ImageProcessingService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Thumbnails are generated lazily on first view; this pre-generates them so the first visit of a page is not slow. */
#[AsCommand(name: 'app:photos:warm', description: 'Pre-generates the cached photo thumbnails')]
class PhotosWarmCommand
{
    public function __construct(
        private readonly PhotoRepository $photos,
        private readonly ImageProcessingService $images,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Variants to generate (comma separated)')] string $variants = 'avatar,thumb',
        #[Option(description: 'Only generate the N most recent photos')] ?int $limit = null,
    ): int {
        $variants = array_filter(array_map('trim', explode(',', $variants)));
        $query = $this->photos->createQueryBuilder('p')->select('p.filename')->orderBy('p.id', 'DESC');
        if (null !== $limit) {
            $query->setMaxResults($limit);
        }
        $filenames = array_column($query->getQuery()->getArrayResult(), 'filename');

        $failed = 0;
        $io->progressStart(count($filenames));
        foreach ($filenames as $filename) {
            foreach ($variants as $variant) {
                if (null === $this->images->thumbnailPath($filename, $variant)) {
                    ++$failed;
                }
            }
            $io->progressAdvance();
        }
        $io->progressFinish();
        $failed ? $io->warning(sprintf('%d thumbnail(s) could not be generated (missing or unreadable originals).', $failed)) : $io->success('Thumbnails are up to date.');

        return Command::SUCCESS;
    }
}
