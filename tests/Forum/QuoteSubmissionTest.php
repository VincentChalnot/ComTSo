<?php

namespace App\Tests\Forum;

use App\Entity\Quote;
use App\Form\QuoteType;
use App\Repository\QuoteRepository;
use App\Tests\Support\AppWebTestCase;

/** Any member may add a quote from the button under the quote of the aside; nothing else is open to them. */
class QuoteSubmissionTest extends AppWebTestCase
{
    private function submit(string $content, string $author): void
    {
        $crawler = $this->client->request('GET', '/citations/nouvelle');
        $form = $crawler->selectButton('Ajouter la citation')->form(['quote[content]' => $content, 'quote[originalAuthor]' => $author]);
        $this->client->submit($form);
    }

    public function testEveryPageOffersTheButtonEvenWithoutAnyQuote(): void
    {
        $this->loginAs($this->createUser('alice'));

        $crawler = $this->client->request('GET', '/');

        self::assertCount(1, $crawler->filter('.layout-aside a.aside-quote-add[href="/citations/nouvelle"]'));
    }

    public function testAnyMemberAddsAQuoteRecordedUnderTheirName(): void
    {
        $alice = $this->createUser('alice');
        $this->loginAs($alice);

        $this->submit("  Il faut cultiver notre jardin.  \n", ' Voltaire ');

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'la citation a été ajoutée');
        $this->em()->clear();
        $quotes = $this->em()->getRepository(Quote::class)->findAll();
        self::assertCount(1, $quotes);
        self::assertSame('Il faut cultiver notre jardin.', $quotes[0]->getContent(), 'trimmed');
        self::assertSame('Voltaire', $quotes[0]->getOriginalAuthor());
        self::assertSame('alice', $quotes[0]->getAuthor()->getUsername());
    }

    public function testTheNewQuoteCanBeDrawnAndIsShownAsPlainText(): void
    {
        $this->loginAs($this->createUser('alice'));
        $this->submit('<script>alert(1)</script> <b>gras</b>', 'Moi');

        $quote = static::getContainer()->get(QuoteRepository::class)->findRandom();
        self::assertSame('<script>alert(1)</script> <b>gras</b>', $quote->getContent());

        $crawler = $this->client->request('GET', '/');
        self::assertSame('<script>alert(1)</script> <b>gras</b>', $crawler->filter('.aside-quote blockquote')->text());
        self::assertStringNotContainsString('<script>alert(1)', $this->client->getResponse()->getContent());
        self::assertCount(0, $crawler->filter('.aside-quote blockquote b'));
    }

    public function testEmptyAndOversizedInputIsRefusedWith422AndTypedTextIsKept(): void
    {
        $this->loginAs($this->createUser('alice'));

        $this->submit("   \n ", 'Voltaire');
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Le texte de la citation est obligatoire', $this->client->getResponse()->getContent());

        $this->submit('Une citation', '   ');
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('L’auteur de la citation est obligatoire', $this->client->getResponse()->getContent());

        $this->submit(str_repeat('a', QuoteType::MAX_CONTENT + 1), 'Voltaire');
        self::assertResponseStatusCodeSame(422);
        $this->submit('Une citation', str_repeat('b', QuoteType::MAX_AUTHOR + 1));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('input[name="quote[originalAuthor]"][value^="bbbb"]');

        $this->submit(str_repeat('a', QuoteType::MAX_CONTENT), str_repeat('b', QuoteType::MAX_AUTHOR));
        self::assertResponseRedirects('/');
        self::assertSame(1, $this->em()->getRepository(Quote::class)->count([]), 'only the maximal quote was stored');
    }

    public function testAPostWithoutTheFormTokenStoresNothing(): void
    {
        $this->loginAs($this->createUser('alice'));

        $this->client->request('POST', '/citations/nouvelle', ['quote' => ['content' => 'Forgée', 'originalAuthor' => 'Forgeur']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em()->getRepository(Quote::class)->count([]));
    }

    public function testAnonymousVisitorsAreSentToTheLogin(): void
    {
        $this->client->request('GET', '/citations/nouvelle');
        self::assertResponseRedirects('/login');

        $this->client->request('POST', '/citations/nouvelle', ['quote' => ['content' => 'x', 'originalAuthor' => 'y']]);
        self::assertResponseRedirects('/login');
        self::assertSame(0, $this->em()->getRepository(Quote::class)->count([]));
    }

    public function testMembersCannotAddMoreFieldsThanTheTwoOfTheForm(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $this->loginAs($alice);
        $crawler = $this->client->request('GET', '/citations/nouvelle');
        $values = $crawler->selectButton('Ajouter la citation')->form()->getPhpValues();
        $values['quote']['content'] = 'Ok';
        $values['quote']['originalAuthor'] = 'Moi';
        $values['quote']['author'] = (string) $bob->getId();
        $this->client->request('POST', '/citations/nouvelle', $values);

        self::assertResponseStatusCodeSame(422, 'an extra field makes the form invalid');
        self::assertSame(0, $this->em()->getRepository(Quote::class)->count([]));
    }
}
