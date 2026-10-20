<?php

namespace App\Tests\Admin;

/** The admin pages use the whole width of the viewport (`.page-wide`) and keep the site masthead and footer. */
class AdminLayoutTest extends AdminWebTestCase
{
    public function testAdminPagesUseTheWholeWidthButTheRestOfTheSiteDoesNot(): void
    {
        $this->loginAdmin();

        foreach (['/admin', '/admin/users/', '/admin/forums/'] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertSame(1, $crawler->filter('body > .page.page-wide')->count(), $url);
            self::assertSame(1, $crawler->filter('.masthead')->count(), 'the site masthead stays on '.$url);
            self::assertSame(1, $crawler->filter('footer.colophon')->count(), 'and the footer on '.$url);
        }

        $crawler = $this->client->request('GET', '/forums/');
        self::assertSame(0, $crawler->filter('.page-wide')->count());
    }
}
