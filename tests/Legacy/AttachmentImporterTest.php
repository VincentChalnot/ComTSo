<?php

namespace App\Tests\Legacy;

use App\Entity\Comment;
use App\Legacy\Import\AttachmentImporter;
use App\Legacy\Import\ImportReport;
use App\Legacy\Import\ReimportOptions;
use App\Legacy\Import\UserMapper;
use App\Legacy\Model\LegacyAttachment;
use App\Legacy\Model\LegacyUser;
use App\Photo\PhotoPipeline;
use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\ExifJpeg;
use App\Tests\Support\PhotoFilesTrait;
use Symfony\Component\Filesystem\Filesystem;

/** Files attached to phpBB 3 posts become photos of the live message; broken ones are reported, never silently dropped. */
final class AttachmentImporterTest extends AppWebTestCase
{
    use PhotoFilesTrait;

    private string $phpbb3;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePhotoDirectory();
        $this->phpbb3 = sys_get_temp_dir().'/phpbb3-'.bin2hex(random_bytes(4));
        mkdir($this->phpbb3.'/phpBB3/files', 0775, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->phpbb3);
        $this->cleanPhotoDirectory();
        parent::tearDown();
    }

    private function file(string $name, string $bytes): void
    {
        file_put_contents($this->phpbb3.'/phpBB3/files/'.$name, $bytes);
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(30, 20);
        imagefill($image, 0, 0, imagecolorallocate($image, 10, 200, 90));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function attachment(int $id, int $post, string $physical, string $real, int $size = 0, string $comment = ''): LegacyAttachment
    {
        return new LegacyAttachment($id, $post, 54, $physical, $real, $comment, 'image/jpeg', $size, new \DateTimeImmutable('2008-04-05 10:00:00'));
    }

    /** @param list<LegacyAttachment> $attachments @param array<string, int> $posts */
    private function importAttachments(array $attachments, array $posts, ImportReport $report = new ImportReport(), bool $dryRun = false): ImportReport
    {
        $connection = $this->em()->getConnection();
        $users = new UserMapper($connection, $dryRun);
        $users->register([new LegacyUser('phpbb3', 54, 'alice', 'alice@example.test', null)]);
        $options = new ReimportOptions($dryRun, ['phpbb3'], 300, null, $this->phpbb3, $this->photoDir, '/tmp');
        (new AttachmentImporter($connection, $users, $report, $options, static::getContainer()->get(PhotoPipeline::class)))->import($attachments, $posts);

        return $report;
    }

    private function skippedReasons(ImportReport $report): array
    {
        return array_keys($report->rows()['phpbb3']['attachments']['skipped'] ?? []);
    }

    public function testReadableImagesBecomePhotosOfTheLiveMessageAndTheirMarkersGoAway(): void
    {
        $alice = $this->createUser('alice');
        $comment = $this->createComment($this->createTopic($this->createForum(), $alice), $alice, '<p>Regardez</p><br />[attachment=1]fille.jpg[/attachment]<br />[attachment=0]Photo 3.jpg[/attachment]<br />fin');
        $jpeg = ExifJpeg::build(300, 200, ['Model' => 'Old cam', 'DateTimeOriginal' => '2008:04:05 09:00:00']);
        $this->file('2_aaa', $jpeg);
        $this->file('2_bbb', $this->png());
        $report = $this->importAttachments(
            [$this->attachment(27, 2176, '2_aaa', 'fille.jpg', \strlen($jpeg), 'Et voilà !'), $this->attachment(28, 2176, '2_bbb', 'Photo 3.jpg')],
            ['phpbb3:post:2176' => $comment->getId()],
        );

        $row = $report->rows()['phpbb3']['attachments'];
        self::assertSame([2, 2, 0], [$row['total'], $row['imported'], $row['present']]);
        $this->em()->clear();
        $comment = $this->em()->find(Comment::class, $comment->getId());
        self::assertSame(['fille.jpg', 'Photo 3.jpg'], array_map(fn ($l) => $l->getPhoto()->getOriginalFilename(), $comment->getPhotos()->toArray()));
        $photo = $comment->getPhotos()->first()->getPhoto();
        self::assertSame('Et voilà !', $photo->getTitle());
        self::assertSame('image/webp', $photo->getFileType());
        self::assertSame('Old cam', $photo->getExif()['Model'], 'Through the pipeline: EXIF is read.');
        self::assertSame('phpbb3:attachment:27', $this->em()->getConnection()->fetchOne('SELECT legacy_ref FROM ctso_photo WHERE id = ?', [$photo->getId()]));
        self::assertSame('<p>Regardez</p><br /><br /><br />fin', $comment->getContent(), 'The markers are gone, the photos are shown under the message.');
        self::assertSame('phpbb3:post:2176', $this->em()->getConnection()->fetchOne('SELECT legacy_ref FROM ctso_comment WHERE id = ?', [$comment->getId()]), 'Remembered, so that a second run cannot insert the post again.');
        self::assertCount(2, $this->storedFiles());
    }

    public function testSecondRunChangesNothing(): void
    {
        $alice = $this->createUser('alice');
        $comment = $this->createComment($this->createTopic($this->createForum(), $alice), $alice, '[attachment=0]a.jpg[/attachment]');
        $this->file('2_aaa', ExifJpeg::build(100, 80));
        $attachments = [$this->attachment(1, 10, '2_aaa', 'a.jpg')];
        $posts = ['phpbb3:post:10' => $comment->getId()];
        $this->importAttachments($attachments, $posts);
        $files = $this->storedFiles();

        $second = $this->importAttachments($attachments, $posts);

        $row = $second->rows()['phpbb3']['attachments'];
        self::assertSame([1, 0, 1], [$row['total'], $row['imported'], $row['present']]);
        self::assertSame($files, $this->storedFiles());
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ctso_comment_photo'));
    }

    public function testBrokenMissingAndOrphanFilesAreReportedAndLeaveAVisibleNote(): void
    {
        $alice = $this->createUser('alice');
        $comment = $this->createComment($this->createTopic($this->createForum(), $alice), $alice, '[attachment=2]truncated.jpg[/attachment] [attachment=1]gone.jpg[/attachment] [attachment=0]notes.jpg[/attachment]');
        $this->file('2_trunc', substr(ExifJpeg::build(300, 200), 0, 40)); // header only
        $this->file('2_text', 'This is not an image at all');
        $report = $this->importAttachments(
            [
                $this->attachment(1, 5, '2_trunc', 'truncated.jpg'),
                $this->attachment(2, 5, '2_nowhere', 'gone.jpg'),
                $this->attachment(3, 5, '2_text', 'notes.jpg'),
                $this->attachment(4, 0, '2_text', 'orphan.jpg'),
            ],
            ['phpbb3:post:5' => $comment->getId()],
        );

        $row = $report->rows()['phpbb3']['attachments'];
        self::assertSame([4, 0], [$row['total'], $row['imported']]);
        self::assertEqualsCanonicalizing(['damaged file, not usable', 'file does not exist in the phpBB 3 tree (lost)', 'orphan (never attached to a post)'], $this->skippedReasons($report));
        $lines = implode("\n", $report->details()['phpbb3 attachments not imported']);
        self::assertStringContainsString('#1 "truncated.jpg"', $lines);
        self::assertStringContainsString('2_nowhere', $lines, 'The path that was looked for is reported.');
        self::assertSame([], $this->storedFiles(), 'Nothing is stored for a refused file.');
        self::assertSame(
            '<em>[pièce jointe perdue : truncated.jpg]</em> <em>[pièce jointe perdue : gone.jpg]</em> <em>[pièce jointe perdue : notes.jpg]</em>',
            $this->em()->getConnection()->fetchOne('SELECT content FROM ctso_comment WHERE id = ?', [$comment->getId()]),
        );
    }

    public function testPngWhoseSignatureLostItsCarriageReturnIsRepairedAndSizeLossIsReported(): void
    {
        $alice = $this->createUser('alice');
        $comment = $this->createComment($this->createTopic($this->createForum(), $alice), $alice, '<p>x</p>[attachment=0]c.png[/attachment]');
        $png = $this->png();
        $this->file('2_png', "\x89PNG\x0A\x1A\x0A".substr($png, 8)); // FTP text mode turned CR LF into LF
        $report = $this->importAttachments([$this->attachment(3, 73, '2_png', 'c.png', \strlen($png))], ['phpbb3:post:73' => $comment->getId()]);

        self::assertSame(1, $report->rows()['phpbb3']['attachments']['imported']);
        $detail = $report->details()['phpbb3 attachments imported'][0];
        self::assertStringContainsString('1 byte(s) shorter', $detail);
        self::assertStringContainsString('PNG signature restored', $detail);
        self::assertSame('<p>x</p>', $this->em()->getConnection()->fetchOne('SELECT content FROM ctso_comment WHERE id = ?', [$comment->getId()]));
    }

    public function testDryRunWritesNothing(): void
    {
        $alice = $this->createUser('alice');
        $comment = $this->createComment($this->createTopic($this->createForum(), $alice), $alice, '[attachment=0]a.jpg[/attachment]');
        $this->file('2_aaa', ExifJpeg::build(100, 80));

        $report = $this->importAttachments([$this->attachment(1, 10, '2_aaa', 'a.jpg')], ['phpbb3:post:10' => $comment->getId()], dryRun: true);

        self::assertSame(1, $report->rows()['phpbb3']['attachments']['imported']);
        self::assertSame([], $this->storedFiles());
        self::assertSame('[attachment=0]a.jpg[/attachment]', $this->em()->getConnection()->fetchOne('SELECT content FROM ctso_comment WHERE id = ?', [$comment->getId()]));
    }
}
