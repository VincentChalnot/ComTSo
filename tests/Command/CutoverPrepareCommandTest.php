<?php

namespace App\Tests\Command;

use App\Entity\ChatMessage;
use App\Entity\Message;
use App\Entity\Photo;
use App\Entity\Topic;
use App\Entity\User;
use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\PhotoFilesTrait;
use App\Tests\Support\PhotoRowsTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class CutoverPrepareCommandTest extends AppWebTestCase
{
    use PhotoFilesTrait;
    use PhotoRowsTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePhotoDirectory();
    }

    protected function tearDown(): void
    {
        $this->cleanPhotoDirectory();
        parent::tearDown();
    }

    private function execute(array $input = []): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:cutover:prepare'));
        $tester->execute($input, ['decorated' => false]);
        $this->em()->clear();

        return $tester;
    }

    private function chat(User $author, string $text = 'Salut'): ChatMessage
    {
        $message = (new ChatMessage())->setContent($text)->setAuthor($author);
        $this->em()->persist($message);
        $this->em()->flush();

        return $message;
    }

    private function reload(User $user): User
    {
        return $this->em()->find(User::class, $user->getId());
    }

    public function testEveryMembersPointersMoveToNow(): void
    {
        $never = $this->createUser('never');
        $long = $this->createUser('long');
        $long->setPreviousLogin(new \DateTime('2006-01-01'));
        $disabled = $this->createUser('retired')->setEnabled(false);
        $this->chat($long);
        $latest = $this->chat($long, 'Dernier');
        $this->em()->flush();

        $tester = $this->execute();

        self::assertSame(0, $tester->getStatusCode());
        foreach ([$never, $long, $disabled] as $user) {
            $reloaded = $this->reload($user);
            self::assertGreaterThan(new \DateTime('-1 minute'), $reloaded->getPreviousLogin(), $user->getUsername());
            self::assertSame($latest->getId(), $reloaded->getChatLastReadId(), $user->getUsername());
        }
    }

    public function testItNeverMovesAPointerBackwards(): void
    {
        $ahead = $this->createUser('ahead');
        $ahead->setPreviousLogin(new \DateTime('+1 day'))->setChatLastReadId(999999);
        $this->chat($ahead);
        $this->em()->flush();

        $this->execute();

        $reloaded = $this->reload($ahead);
        self::assertSame(999999, $reloaded->getChatLastReadId());
        self::assertGreaterThan(new \DateTime('+23 hours'), $reloaded->getPreviousLogin());
    }

    public function testItCanBeRunAgain(): void
    {
        $alice = $this->createUser('alice');
        $message = $this->chat($alice);

        $this->execute();
        $second = $this->execute();

        self::assertSame(0, $second->getStatusCode());
        self::assertSame($message->getId(), $this->reload($alice)->getChatLastReadId());
    }

    public function testADryRunWritesNothingButReportsWhatWouldHappen(): void
    {
        $alice = $this->createUser('alice');
        $this->createUser('test-account');
        $this->chat($alice);

        $tester = $this->execute(['--dry-run' => true, '--delete-user' => ['test-account']]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('would', $tester->getDisplay());
        $reloaded = $this->reload($alice);
        self::assertNull($reloaded->getPreviousLogin());
        self::assertSame(0, $reloaded->getChatLastReadId());
        self::assertNotNull($this->em()->getRepository(User::class)->findOneBy(['username' => 'test-account']), 'nobody is deleted');
    }

    public function testTestAccountsAreDeletedWithWhatTheyWrote(): void
    {
        $alice = $this->createUser('alice');
        $tester1 = $this->createUser('tester-one');
        $tester2 = $this->createUser('tester-two');
        $forum = $this->createForum();
        $testTopic = $this->createTopic($forum, $tester1, 'Essai');
        $this->createComment($testTopic, $alice);
        $aliceTopic = $this->createTopic($forum, $alice, 'Vrai sujet');
        $this->createComment($aliceTopic, $alice);
        $this->createComment($aliceTopic, $tester1, '<p>test</p>');
        $this->createComment($aliceTopic, $tester2, '<p>test</p>');
        $this->createComment($aliceTopic, $alice, '<p>vrai</p>');
        $avatar = $this->photoRow();
        file_put_contents($this->photoDir.'/'.$avatar->getFilename(), 'x');
        $tester1->setAvatar($avatar);
        $this->em()->persist((new Message())->setAuthor($tester1)->setRecipient($alice)->setContent('<p>coucou</p>'));
        $this->em()->persist((new Message())->setAuthor($alice)->setRecipient($tester2)->setContent('<p>salut</p>'));
        $this->em()->persist((new Message())->setAuthor($alice)->setRecipient($alice)->setContent('<p>memo</p>'));
        $this->chat($tester1, 'test chat');
        $this->chat($alice, 'vrai chat');
        $this->em()->flush();

        $tester = $this->execute(['--delete-user' => ['tester-one', 'tester-two', 'never-existed']]);

        self::assertSame(0, $tester->getStatusCode(), 'an account that is already gone is not an error');
        $users = $this->em()->getRepository(User::class);
        self::assertNull($users->findOneBy(['username' => 'tester-one']));
        self::assertNull($users->findOneBy(['username' => 'tester-two']));
        self::assertNotNull($users->findOneBy(['username' => 'alice']));
        self::assertNull($this->em()->find(Topic::class, $testTopic->getId()), 'their topic goes with its messages');
        $kept = $this->em()->find(Topic::class, $aliceTopic->getId());
        self::assertSame(2, $kept->getCommentCount(), 'the message counter of a topic they posted in is corrected');
        self::assertSame(2, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ctso_comment'));
        self::assertSame(1, $this->em()->getRepository(Message::class)->count([]), 'private messages in both directions are gone, memos stay');
        self::assertSame(['vrai chat'], array_map(fn (ChatMessage $m) => $m->getContent(), $this->em()->getRepository(ChatMessage::class)->findAll()));
        self::assertNull($this->em()->find(Photo::class, $avatar->getId()), 'an avatar nobody uses any more is removed...');
        self::assertFileDoesNotExist($this->photoDir.'/'.$avatar->getFilename(), '...with its file');
    }

    public function testItReportsAdministratorsDisabledAccountsAndMembersWithoutAMailbox(): void
    {
        $this->createUser('root', ['ROLE_ADMIN']);
        $this->createUser('regular');
        $this->createUser('retired')->setEnabled(false);
        $this->createUser('placeholder')->setEmail('placeholder@legacy.invalid');
        $this->createUser('bare')->setEmail('');
        $this->em()->flush();

        $display = $this->execute()->getDisplay();

        self::assertMatchesRegularExpression('~ROLE_ADMIN \(1\): root~', $display);
        self::assertMatchesRegularExpression('~Disabled accounts[^\n]*: 1~', $display);
        self::assertMatchesRegularExpression('~without a usable e-mail[^\n]*\(2\): [^\n]*(placeholder[^\n]*bare|bare[^\n]*placeholder)~', $display);
        self::assertStringNotContainsString('retired,', $display);
    }
}
