<?php

namespace App\Tests\Forum;

use App\Entity\ChatMessage;
use App\Entity\User;
use App\Stats\MessageStatistics;
use App\Tests\Support\AppWebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** The statistics page: aggregate correctness, access, privacy, one query whatever the volume. */
class StatisticsTest extends AppWebTestCase
{
    private function fixture(): User
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $ghost = $this->createUser('ghost');
        $ghost->setEnabled(false)->setEmail('ghost@legacy.invalid');
        $topic = $this->createTopic($this->createForum(), $alice);
        foreach ([[$alice, '2019-03-01'], [$alice, '2019-05-01'], [$alice, '2021-01-01'], [$bob, '2021-02-01'], [$ghost, '2021-03-01'], [$ghost, '2021-04-01'], [$ghost, '2021-05-01']] as [$author, $date]) {
            $this->createComment($topic, $author, '<p>x</p>', new \DateTime($date.' 12:00:00'));
        }
        $orphan = $this->createComment($topic, $alice, '<p>old</p>', new \DateTime('2019-06-01'));
        $orphan->setAuthor(null);
        $this->em()->persist((new ChatMessage())->setContent('a')->setAuthor($bob)->setCreatedAt(new \DateTime('2020-01-01'))->setUpdatedAt(new \DateTime('2020-01-01')));
        $this->em()->persist((new ChatMessage())->setContent('b')->setAuthor($bob)->setCreatedAt(new \DateTime('2021-01-01'))->setUpdatedAt(new \DateTime('2021-01-01')));
        $this->em()->flush();

        return $alice;
    }

    public function testTheAggregateCountsMessagesPerMemberAndYear(): void
    {
        $this->fixture();

        $report = static::getContainer()->get(MessageStatistics::class)->report();

        self::assertSame([2019, 2020, 2021], $report['years'], 'gaps between the first and last year are kept');
        self::assertSame(['comments' => 8, 'chat' => 2], $report['totals']);
        self::assertSame(
            [['year' => 2019, 'comments' => 3, 'chat' => 0], ['year' => 2020, 'comments' => 0, 'chat' => 1], ['year' => 2021, 'comments' => 5, 'chat' => 1]],
            $report['yearTotals'],
        );
        $byName = [];
        foreach ($report['members'] as $m) {
            $byName[$m['username'] ?? 'former'] = $m;
        }
        self::assertSame([2019 => 2, 2021 => 1], $byName['alice']['comments']);
        self::assertSame(3, $byName['alice']['commentTotal']);
        self::assertSame([2021 => 3], $byName['ghost']['comments']);
        self::assertFalse($byName['ghost']['enabled']);
        self::assertSame([2020 => 1, 2021 => 1], $byName['bob']['chat']);
        self::assertSame(2, $byName['bob']['chatTotal']);
        self::assertSame(1, $byName['former']['commentTotal'], 'a message without author is counted as a former member');
        self::assertSame(['alice', 'bob', 'ghost', null], array_column($report['members'], 'username'), 'busiest first (three way tie here: by pseudo), former members last as the least active');
        self::assertArrayNotHasKey('email', $report['members'][0]);
    }

    public function testAnEmptyForumHasAnEmptyReport(): void
    {
        $report = static::getContainer()->get(MessageStatistics::class)->report();

        self::assertSame([], $report['years']);
        self::assertSame([], $report['members']);
    }

    public function testThePageShowsTheTableAndTheChartsInsideTheSettingsCentre(): void
    {
        $alice = $this->fixture();
        $this->loginAs($alice);

        $crawler = $this->client->request('GET', '/gens/alice/parametres/statistiques');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('table[data-stats-table] tbody tr')->each(static fn (Crawler $tr) => array_map('trim', $tr->filter('th, td')->each(static fn (Crawler $c) => preg_replace('/\s+/', ' ', $c->text()))));
        self::assertSame(['alice', '2', '', '1', '3', '0'], $rows[0]);
        self::assertSame(['bob', '', '', '1', '1', '2'], $rows[1]);
        self::assertSame(['ghost', '', '', '3', '3', '0'], $rows[2]);
        self::assertSame(['Anciens membres', '1', '', '', '1', '0'], $rows[3]);
        self::assertSame(['Total', '3', '0', '5', '8', '2'], array_map('trim', $crawler->filter('table tfoot th, table tfoot td')->each(static fn (Crawler $c) => $c->text())));

        $charts = $crawler->filter('[data-vue-component="StatsChart"]');
        self::assertCount(2, $charts);
        $years = json_decode($charts->eq(0)->attr('data-props'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['2019', '2020', '2021'], $years['labels']);
        self::assertSame([3, 0, 5], $years['series'][0]['values']);
        self::assertSame([0, 1, 1], $years['series'][1]['values']);

        self::assertSame('Statistiques', trim($crawler->filter('.settings-nav a[aria-current="page"]')->text()));
    }

    public function testDisabledMembersAreNamedButNeverLinkedAndNoAddressLeaks(): void
    {
        $alice = $this->fixture();
        $this->loginAs($alice);

        $crawler = $this->client->request('GET', '/gens/alice/parametres/statistiques');
        $html = $this->client->getResponse()->getContent();

        self::assertCount(1, $crawler->filter('table[data-stats-table] tbody th a[href="/gens/alice"]'));
        self::assertCount(0, $crawler->filter('table[data-stats-table] tbody th a[href="/gens/ghost"]'), 'no profile link for a disabled account');
        self::assertStringContainsString('ghost', $crawler->filter('table[data-stats-table]')->text());
        foreach (['ghost@legacy.invalid', '@example.test', '.invalid'] as $leak) {
            self::assertStringNotContainsString($leak, $html);
        }
    }

    public function testAnEmptyForumShowsAFriendlyMessageInsteadOfCharts(): void
    {
        $this->loginAs($this->createUser('alice'));

        $crawler = $this->client->request('GET', '/gens/alice/parametres/statistiques');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Aucun message', $crawler->filter('.standfirst')->text());
        self::assertCount(0, $crawler->filter('[data-vue-component]:not([data-vue-component=ThemeToggle]), table[data-stats-table]'));
    }

    public function testOnlyMembersCanSeeIt(): void
    {
        $this->client->request('GET', '/gens/alice/parametres/statistiques');

        self::assertResponseRedirects('/login');
    }

    public function testOneAggregateQueryWhateverTheVolume(): void
    {
        $alice = $this->fixture();
        $this->loginAs($alice);
        $count = function (): int {
            $this->client->enableProfiler();
            $this->client->request('GET', '/gens/alice/parametres/statistiques');
            $queries = $this->client->getProfile()->getCollector('db')->getQueries();

            return \count(array_filter(array_merge(...array_values($queries)), static fn (array $q): bool => str_contains($q['sql'], 'GROUP BY') && str_contains($q['sql'], 'ctso_comment')));
        };
        $this->client->request('GET', '/gens/alice/parametres/statistiques'); // warm-up
        self::assertSame(1, $count());

        $topic = $this->em()->getRepository(\App\Entity\Topic::class)->findOneBy([]);
        foreach (range(1, 30) as $i) {
            $this->createComment($topic, $this->em()->getRepository(User::class)->findOneBy(['username' => 'bob']), '<p>y</p>', new \DateTime('2015-01-01 +'.$i.' days'));
        }
        self::assertSame(1, $count(), 'more messages, still one aggregate query');
    }
}
