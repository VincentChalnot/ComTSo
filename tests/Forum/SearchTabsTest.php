<?php

namespace App\Tests\Forum;

use App\Entity\ChatMessage;
use App\Entity\Quote;
use App\Entity\User;
use App\Search\SiteSearch;
use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\PhotoRowsTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

/** The search page: one tab per kind of result, counts, bounded pages, plain links. */
class SearchTabsTest extends AppWebTestCase
{
    use PhotoRowsTrait;

    private function member(): User
    {
        $alice = $this->createUser('alice');
        $this->loginAs($alice);

        return $alice;
    }

    private function open(string $query): Crawler
    {
        $this->client->request('GET', '/recherche?'.$query);
        self::assertResponseIsSuccessful();

        return $this->client->getCrawler();
    }

    /** @return list<string> */
    private function tabLabels(Crawler $crawler): array
    {
        return $crawler->filter('nav.tabs a')->each(static fn (Crawler $a) => trim(preg_replace('/\s+/', ' ', $a->text())));
    }

    private function activeTab(Crawler $crawler): string
    {
        return explode(' ', trim($crawler->filter('nav.tabs a[aria-current=page]')->text()))[0];
    }

    private function commentsAbout(string $word, int $count, User $author): void
    {
        $topic = $this->createTopic($this->createForum(), $author, 'Fil des marmottes');
        for ($i = 1; $i <= $count; ++$i) {
            $this->createComment($topic, $author, "<p>Message numéro $i sur la $word</p>", new \DateTime('2026-01-01 10:00:00 +'.$i.' minutes'));
        }
    }

    public function testResultsOfATabAreBoundedAndPaginated(): void
    {
        $alice = $this->member();
        $perPage = SiteSearch::TABS['comments']['perPage'];
        $this->commentsAbout('marmotte', $perPage * 2 + 5, $alice);

        $first = $this->open('q=marmotte&tab=comments');
        self::assertCount($perPage, $first->filter('ol.result-list > li'));
        self::assertStringContainsString('numéro '.($perPage * 2 + 5).' ', $first->filter('ol.result-list > li')->first()->text(), 'Newest message first.');
        self::assertStringContainsString('page 1 sur 3', $first->filter('h2.section-title')->text());

        $links = $first->filter('nav.pager a')->each(static fn (Crawler $a) => $a->attr('href'));
        self::assertContains('/recherche?q=marmotte&tab=comments&page=2', $links);
        self::assertContains('/recherche?q=marmotte&tab=comments&page=3', $links);

        $second = $this->open('q=marmotte&tab=comments&page=2');
        self::assertCount($perPage, $second->filter('ol.result-list > li'));
        self::assertStringContainsString('numéro '.($perPage + 5).' ', $second->filter('ol.result-list > li')->first()->text());

        $last = $this->open('q=marmotte&tab=comments&page=3');
        self::assertCount(5, $last->filter('ol.result-list > li'));
        self::assertStringContainsString('numéro 1 ', $last->filter('ol.result-list > li')->last()->text(), 'The oldest match is on the last page.');
        self::assertCount(0, $last->filter('a[rel=next]'));
    }

    public function testNoPagerWhenEverythingFitsOnOnePage(): void
    {
        $alice = $this->member();
        $this->commentsAbout('marmotte', 3, $alice);

        $crawler = $this->open('q=marmotte');

        self::assertCount(3, $crawler->filter('ol.result-list > li'));
        self::assertCount(0, $crawler->filter('nav.pager'));
    }

    #[DataProvider('badPages')]
    public function testInvalidPageNumbersAreClamped(string $page, string $expectedFirst): void
    {
        $alice = $this->member();
        $this->commentsAbout('marmotte', SiteSearch::TABS['comments']['perPage'] + 1, $alice);

        $crawler = $this->open('q=marmotte&tab=comments&page='.$page);

        self::assertStringContainsString($expectedFirst, $crawler->filter('ol.result-list > li')->first()->text());
    }

    /** @return iterable<string, array{string, string}> */
    public static function badPages(): iterable
    {
        $total = SiteSearch::TABS['comments']['perPage'] + 1;
        yield 'zero' => ['0', "numéro $total "];
        yield 'negative' => ['-4', "numéro $total "];
        yield 'text' => ['abc', "numéro $total "];
        yield 'empty' => ['', "numéro $total "];
        yield 'far beyond the last page' => ['9999', 'numéro 1 '];
        yield 'overflowing integer' => ['99999999999999999999', "numéro $total "];
    }

    public function testTabsShowTheCountOfEachKindAndKeepTheQuery(): void
    {
        $alice = $this->member();
        $topic = $this->createTopic($this->createForum(), $alice, 'Voyage en Islande');
        $this->createComment($topic, $alice, '<p>L\'Islande en hiver</p>');
        $this->createComment($topic, $alice, '<p>Islande, jour deux</p>');
        $this->photoRow('Glacier islandais');
        $this->createUser('islandaise');
        foreach (['Islande, terre de feu', 'Le froid de l\'Islande', 'Pays: Islande'] as $text) {
            $this->em()->persist((new Quote())->setContent($text)->setOriginalAuthor('Anonyme')->setCreatedAt(new \DateTime())->setUpdatedAt(new \DateTime()));
        }
        $this->em()->persist((new ChatMessage())->setContent('Qui part en Islande ?')->setAuthor($alice)->setCreatedAt(new \DateTime())->setUpdatedAt(new \DateTime()));
        $this->em()->flush();

        $crawler = $this->open('q='.rawurlencode('Islande'));

        // "islandais" and "islandaise" do not contain "Islande": no photo, no member.
        self::assertSame(['Messages 2 résultats', 'Sujets 1 résultat', 'Photos 0 résultat', 'Chat 1 résultat', 'Membres 0 résultat', 'Citations 3 résultats'], $this->tabLabels($crawler));
        self::assertStringContainsString('7 résultats', $crawler->filter('.standfirst')->text());

        $hrefs = $crawler->filter('nav.tabs a')->each(static fn (Crawler $a) => $a->attr('href'));
        self::assertSame(
            array_map(static fn (string $tab) => '/recherche?q=Islande&tab='.$tab, array_keys(SiteSearch::TABS)),
            $hrefs,
            'Every tab is a plain link that carries the term.',
        );
    }

    public function testFirstTabWithHitsOpensByDefaultAndTheRequestedTabWins(): void
    {
        $alice = $this->member();
        $this->em()->persist((new Quote())->setContent('Une citation sur les cactus')->setOriginalAuthor('Anonyme')->setCreatedAt(new \DateTime())->setUpdatedAt(new \DateTime()));
        $this->createTopic($this->createForum(), $alice, 'Cactus en pot');
        $this->em()->flush();

        $default = $this->open('q=cactus');
        self::assertSame('Sujets', $this->activeTab($default));
        self::assertStringContainsString('Cactus en pot', $default->filter('main')->text());
        self::assertStringNotContainsString('Une citation sur les cactus', $default->filter('main')->text(), 'Only the open tab lists its results.');

        $quotes = $this->open('q=cactus&tab=quotes');
        self::assertSame('Citations', $this->activeTab($quotes));
        self::assertStringContainsString('Une citation sur les cactus', $quotes->filter('main')->text());
        self::assertStringNotContainsString('Cactus en pot', $quotes->filter('main')->text());
    }

    public function testUnknownTabFallsBackToTheDefaultOne(): void
    {
        $alice = $this->member();
        $this->createTopic($this->createForum(), $alice, 'Cactus en pot');

        foreach (['tab=nope', 'tab=', 'tab=__proto__', 'tab=COMMENTS'] as $query) {
            $crawler = $this->open("q=cactus&$query");
            self::assertSame('Sujets', $this->activeTab($crawler), $query);
            self::assertStringContainsString('Cactus en pot', $crawler->filter('main')->text(), $query);
        }
    }

    public function testNothingFoundStillShowsTheTabsWithAnEmptyState(): void
    {
        $this->member();

        $crawler = $this->open('q=introuvable');

        self::assertCount(6, $crawler->filter('nav.tabs a'));
        self::assertStringContainsString('Rien ne correspond', $crawler->filter('.standfirst')->text());
        self::assertStringContainsString('Aucun message.', $crawler->filter('main')->text());
        self::assertCount(0, $crawler->filter('nav.pager'));
    }

    public function testEveryTabRendersItsKindOfResult(): void
    {
        $alice = $this->member();
        $topic = $this->createTopic($this->createForum(), $alice, 'Sujet licorne');
        $this->createComment($topic, $alice, '<p>Une licorne passe</p>');
        $photo = $this->photoRow('Photo licorne');
        $this->createUser('licorne-fan');
        $this->em()->persist((new Quote())->setContent('La licorne est une légende')->setOriginalAuthor('Anonyme')->setCreatedAt(new \DateTime())->setUpdatedAt(new \DateTime()));
        $this->em()->persist((new ChatMessage())->setContent('une licorne !')->setAuthor($alice)->setCreatedAt(new \DateTime())->setUpdatedAt(new \DateTime()));
        $this->em()->flush();

        self::assertCount(1, $this->open('q=licorne&tab=comments')->filter('ol.result-list > li'));
        self::assertCount(1, $this->open('q=licorne&tab=topics')->filter('ol.result-list > li'));
        self::assertCount(1, $this->open('q=licorne&tab=chat')->filter('ol.result-list > li'));
        self::assertCount(1, $this->open('q=licorne&tab=quotes')->filter('ol.result-list > li'));
        $people = $this->open('q=licorne&tab=people');
        self::assertSame(['licorne-fan'], $people->filter('ol.result-list h3 a')->each(static fn (Crawler $a) => $a->text()));
        $photos = $this->open('q=licorne&tab=photos');
        self::assertSame(["/photos/{$photo->getId()}"], $photos->filter('.photo-grid a')->each(static fn (Crawler $a) => $a->attr('href')));
    }

    public function testDisabledMembersAreNeitherListedNorCounted(): void
    {
        $this->member();
        $this->createUser('fantome-actif');
        $this->createUser('fantome-parti')->setEnabled(false);
        $this->em()->flush();

        $crawler = $this->open('q=fantome&tab=people');

        self::assertSame(['fantome-actif'], $crawler->filter('ol.result-list h3 a')->each(static fn (Crawler $a) => $a->text()));
        self::assertContains('Membres 1 résultat', $this->tabLabels($crawler));
        self::assertStringNotContainsString('fantome-parti', $crawler->html());
    }

    public function testTermIsEscapedInTabLinksAndPager(): void
    {
        $this->member();

        $crawler = $this->open('q='.rawurlencode('a&b"<c>'));

        $html = $crawler->html();
        self::assertStringNotContainsString('<c>', $html);
        self::assertStringContainsString('tab=comments', $crawler->filter('nav.tabs a')->first()->attr('href'));
        self::assertStringContainsString('q=a%26b%22%3Cc%3E', $crawler->filter('nav.tabs a')->first()->attr('href'));
    }

    public function testTooShortTermShowsNoTabs(): void
    {
        $this->member();

        $crawler = $this->open('q=a&tab=photos');

        self::assertStringContainsString('au moins deux caractères', $crawler->filter('.standfirst')->text());
        self::assertCount(0, $crawler->filter('nav.tabs'));
    }

    public function testSearchIsForMembersOnly(): void
    {
        foreach (['/recherche?q=licorne', '/recherche?q=licorne&tab=photos&page=2', '/recherche?tab=people'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseRedirects('/login', null, $url);
        }
    }

    public function testQueryCountDoesNotGrowWithTheNumberOfResults(): void
    {
        $alice = $this->member();
        $authors = [$alice];
        foreach (['bob', 'carol', 'dave'] as $name) {
            $author = $this->createUser($name);
            $author->setAvatar($this->photoRow());
            $authors[] = $author;
        }
        $this->em()->flush();
        $topic = $this->createTopic($this->createForum(), $alice, 'Fil des animaux');
        $this->createComment($topic, $alice, '<p>Une marmotte</p>');
        $perPage = SiteSearch::TABS['comments']['perPage'];
        for ($i = 1; $i <= $perPage + 5; ++$i) {
            $this->createComment($topic, $authors[$i % 4], "<p>Un chamois $i</p>", new \DateTime("2026-02-01 10:00:00 +$i minutes"));
        }

        $this->queryCount('/recherche?q=marmotte&tab=comments'); // warm-up: the first profile also counts the test's own setup queries
        $small = $this->queryCount('/recherche?q=marmotte&tab=comments');
        self::assertCount(1, $this->client->getCrawler()->filter('ol.result-list > li'));
        $large = $this->queryCount('/recherche?q=chamois&tab=comments');
        self::assertCount($perPage, $this->client->getCrawler()->filter('ol.result-list > li'));

        self::assertSame($small, $large, "A full page of results ran $large queries against $small for one result: something queries per result.");
        self::assertLessThan(15, $large);
    }

    private function queryCount(string $url): int
    {
        $this->client->enableProfiler();
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $profile = $this->client->getProfile();
        self::assertNotFalse($profile);

        return $profile->getCollector('db')->getQueryCount();
    }
}
