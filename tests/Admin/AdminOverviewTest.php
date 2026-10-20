<?php

namespace App\Tests\Admin;

use Symfony\Component\DomCrawler\Crawler;

/** The admin overview and menu: one screen order everywhere, icon tiles with the row count (no description text). */
class AdminOverviewTest extends AdminWebTestCase
{
    private const ORDER = ['Membres', 'Forums', 'Sujets', 'Commentaires', 'Photos', 'Citations'];

    public function testTheOverviewTilesAndTheMenuFollowTheSameOrder(): void
    {
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSame(self::ORDER, $crawler->filter('.admin-tiles .card-title')->each(fn (Crawler $h) => trim($h->text())));
        self::assertSame(['Vue d’ensemble', ...self::ORDER], $crawler->filter('nav.tabs a')->each(fn (Crawler $a) => trim($a->text())));
    }

    public function testEveryTileHasAnIconAndACountInsteadOfADescription(): void
    {
        $this->loginAdmin();
        $this->createUser('alice');

        $crawler = $this->client->request('GET', '/admin');

        $tiles = $crawler->filter('.admin-tiles .admin-tile');
        self::assertCount(count(self::ORDER), $tiles);
        $tiles->each(function (Crawler $tile): void {
            self::assertSame(1, $tile->filter('.admin-tile-icon svg')->count(), $tile->text());
            self::assertSame(1, $tile->filter('.badge-count')->count(), $tile->text());
            self::assertSame(0, $tile->filter('p')->count(), 'no description text in a tile');
        });
        self::assertSame('2', trim($tiles->first()->filter('.badge-count')->text()), 'members: root and alice');
    }
}
