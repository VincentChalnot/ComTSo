<?php

namespace App\Tests\Admin;

use App\Entity\Quote;
use Symfony\Component\DomCrawler\Crawler;

class QuoteAdminTest extends AdminWebTestCase
{
    private function quote(string $content, string $author = 'Anonyme'): Quote
    {
        $quote = (new Quote())->setContent($content)->setOriginalAuthor($author);
        $this->em()->persist($quote);
        $this->em()->flush();

        return $quote;
    }

    public function testTheListShowsTheQuotesNewestFirstAndFiltersThem(): void
    {
        $this->loginAdmin();
        $this->quote('Première citation', 'Alphonse');
        $this->quote('Seconde citation', 'Boris');

        $crawler = $this->client->request('GET', '/admin/quotes/');
        self::assertResponseIsSuccessful();
        self::assertSame(['Seconde citation', 'Première citation'], $this->contents($crawler));

        $crawler = $this->client->request('GET', '/admin/quotes/', ['filters' => ['text' => 'alphonse']]);
        self::assertSame(['Première citation'], $this->contents($crawler));
    }

    public function testAnAdministratorAddsAQuoteAndIsRemembered(): void
    {
        $admin = $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/quotes/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Créer')->form();
        $form['form_quote_create[content]'] = 'Une <b>citation</b> & un test';
        $form['form_quote_create[originalAuthor]'] = 'Quelqu’un';
        $this->client->submit($form);

        self::assertResponseRedirects();
        $quote = $this->em()->getRepository(Quote::class)->findOneBy([]);
        self::assertSame('Une <b>citation</b> & un test', $quote->getContent(), 'plain text, stored as typed');
        self::assertSame($admin->getId(), $quote->getAuthor()?->getId());
        $crawler = $this->client->request('GET', '/admin/quotes/');
        self::assertStringContainsString('Une <b>citation</b> & un test', $crawler->filter('td a')->first()->text(), 'and displayed escaped, not interpreted');
        self::assertSame(0, $crawler->filter('td b')->count());
    }

    public function testBothFieldsAreRequired(): void
    {
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/quotes/new');
        $crawler = $this->client->submit($crawler->selectButton('Créer')->form());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('obligatoire', $crawler->filter('.admin-form')->text());
        self::assertSame(0, $this->em()->getRepository(Quote::class)->count([]));
    }

    public function testAnAdministratorEditsAQuote(): void
    {
        $this->loginAdmin();
        $quote = $this->quote('Avant');

        $crawler = $this->client->request('GET', '/admin/quotes/'.$quote->getId().'/edit');
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['form_quote_edit[content]'] = 'Après';
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->em()->clear();
        self::assertSame('Après', $this->em()->find(Quote::class, $quote->getId())->getContent());
    }

    public function testAQuoteIsDeletedAfterConfirmationWithTheToken(): void
    {
        $this->loginAdmin();
        $quote = $this->quote('À supprimer');
        $id = $quote->getId();

        $crawler = $this->client->request('GET', '/admin/quotes/'.$id.'/delete');
        self::assertResponseIsSuccessful();
        self::assertNotNull($this->em()->find(Quote::class, $id), 'the confirmation page changes nothing');

        $this->client->request('POST', '/admin/quotes/'.$id.'/delete');
        $this->em()->clear();
        self::assertNotNull($this->em()->find(Quote::class, $id), 'no token, no removal');

        $this->client->submit($crawler->selectButton('Supprimer définitivement')->form());
        self::assertResponseRedirects('/admin/quotes/');
        $this->em()->clear();
        self::assertNull($this->em()->find(Quote::class, $id));
    }

    /** @return list<string> */
    private function contents(Crawler $crawler): array
    {
        return $crawler->filter('table.table tbody tr td:first-child')->each(fn (Crawler $td) => trim($td->text()));
    }
}
