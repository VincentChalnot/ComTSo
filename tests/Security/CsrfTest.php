<?php

namespace App\Tests\Security;

use App\Entity\PhotoTopic;
use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\PhotoRowsTrait;

/** Every action that changes something needs a token (or a Symfony form, which carries its own) that a foreign page cannot forge. */
class CsrfTest extends AppWebTestCase
{
    use PhotoRowsTrait;

    /** Routes that check an explicit token with isCsrfTokenValid(). */
    private const TOKEN_ROUTES = [
        'chat_read', 'chat_send', 'comment_delete', 'photo_delete', 'photo_like', 'photo_rotate', 'topic_delete', 'topic_photo_remove',
        'topic_photos_order', 'topic_photos_upload', 'topic_star', 'topic_unstar', 'user_theme',
    ];

    /** Routes whose POST is a Symfony form (token field) or the firewall's own authenticator. */
    private const FORM_ROUTES = ['comment_create', 'people_avatar', 'people_password'];

    /**
     * Admin panel routes (config/packages/sidus.yaml) that accept a POST: the bundle's Symfony forms (edit, create) and the empty
     * confirmation forms of the destructive actions, which all carry a form token. Routes of the bundle are `ANY` unless the
     * configuration says otherwise, so {@see testEveryAdminRouteStatesItsMethods()} insists on explicit methods.
     */
    private const ADMIN_FORM_ROUTES = [];

    public function testEveryRouteThatChangesDataIsKnownToBeProtected(): void
    {
        $unsafe = [];
        foreach (static::getContainer()->get('router')->getRouteCollection() as $name => $route) {
            if (array_intersect($route->getMethods(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
                $unsafe[] = $name;
            }
        }
        sort($unsafe);

        $known = [...self::TOKEN_ROUTES, ...self::FORM_ROUTES, ...self::ADMIN_FORM_ROUTES];
        sort($known);
        self::assertSame($known, $unsafe, 'A new POST/DELETE route must check a CSRF token: add it to the lists of this test and to tokenlessRequests() (admin screens: ADMIN_FORM_ROUTES and tokenlessAdminRequests()).');
    }

    public function testEveryAdminRouteStatesItsMethods(): void
    {
        foreach (static::getContainer()->get('router')->getRouteCollection() as $name => $route) {
            if (!str_starts_with($name, 'sidus_admin.') && 'admin_index' !== $name) {
                continue;
            }
            self::assertNotSame([], $route->getMethods(), $name.' has no `methods` in config/packages/sidus.yaml: it answers every method, which the POST audit above cannot see.');
            self::assertSame([], array_diff($route->getMethods(), ['GET', 'POST']), $name.' must be GET and/or POST (forms), nothing else.');
        }
    }

    public function testNoFormOptsOutOfCsrfProtection(): void
    {
        $dir = static::getContainer()->getParameter('kernel.project_dir').'/src';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ('php' === $file->getExtension()) {
                self::assertStringNotContainsString('csrf_protection', (string) file_get_contents($file->getPathname()), $file->getPathname().' disables the CSRF protection of a form');
            }
        }
    }

    /** @return array<string, array{string, string, array}> route name => [method, url, JSON payload] */
    private function tokenlessRequests(): array
    {
        $admin = $this->createUser('root', ['ROLE_ADMIN']);
        $topic = $this->createTopic($this->createForum(), $admin);
        $comment = $this->createComment($topic, $admin);
        $base = '/forum/general/'.$topic->getId();
        $photo = $this->photoRow();

        return [
            'chat_read' => ['POST', '/chat/read', []],
            'chat_send' => ['POST', '/chat/send', ['content' => 'Salut']],
            'comment_delete' => ['POST', '/comment/'.$comment->getId().'/delete', []],
            'photo_delete' => ['POST', '/photos/'.$photo->getId().'/delete', []],
            'photo_like' => ['POST', '/photos/'.$photo->getId().'/like', []],
            'photo_rotate' => ['POST', '/photos/'.$photo->getId().'/rotate', ['direction' => 'right']],
            'topic_delete' => ['POST', $base.'/delete', []],
            'topic_photo_remove' => ['POST', $base.'/photos/1/remove', ['delete_file' => true]],
            'topic_photos_order' => ['POST', $base.'/photos/order', ['links' => [1]]],
            'topic_photos_upload' => ['POST', $base.'/photos/upload', []],
            'topic_star' => ['POST', $base.'/star', []],
            'topic_unstar' => ['POST', $base.'/unstar', []],
            'user_theme' => ['POST', '/preferences/theme', ['theme' => 'dark']],
        ];
    }

    public function testActionsWithoutTokenAreRefused(): void
    {
        $requests = $this->tokenlessRequests();
        $sorted = array_keys($requests);
        sort($sorted);
        $expected = self::TOKEN_ROUTES;
        sort($expected);
        self::assertSame($expected, $sorted, 'every token route is exercised');
        $this->loginAs($this->em()->getRepository(\App\Entity\User::class)->findOneBy(['username' => 'root']));

        foreach ($requests as $route => [$method, $url, $payload]) {
            $this->client->jsonRequest($method, $url, $payload, server: ['HTTP_ORIGIN' => 'http://localhost']);

            self::assertResponseStatusCodeSame(403, $route.' accepted a request without token');
        }

        $this->em()->clear();
        self::assertSame(1, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ctso_topic'), 'the topic survived');
        self::assertSame(1, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ctso_comment'), 'the message survived');
        self::assertSame(1, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ctso_photo'), 'the photo survived');
    }

    public function testActionsWithAWrongTokenAreRefused(): void
    {
        $requests = $this->tokenlessRequests();
        $this->loginAs($this->em()->getRepository(\App\Entity\User::class)->findOneBy(['username' => 'root']));

        foreach ($requests as $route => [$method, $url, $payload]) {
            $this->client->jsonRequest($method, $url, $payload + ['_token' => 'forged'], server: ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_X_CSRF_TOKEN' => 'forged']);

            self::assertResponseStatusCodeSame(403, $route.' accepted a forged token');
        }
    }

    public function testTheThemeToggleCarriesItsToken(): void
    {
        $alice = $this->createUser('alice');
        $this->loginAs($alice);
        $crawler = $this->client->request('GET', '/gens/alice/config');
        $props = json_decode($crawler->filter('[data-vue-component="ThemeToggle"]')->attr('data-props'), true, flags: \JSON_THROW_ON_ERROR);

        $this->client->jsonRequest('POST', $props['url'], ['theme' => 'dark', '_token' => $props['csrf']]);

        self::assertResponseIsSuccessful();
        $this->em()->clear();
        self::assertSame('dark', $this->em()->find(\App\Entity\User::class, $alice->getId())->getTheme());
    }

    public function testThePhotoManagerSendsTheTokenWithEveryUpload(): void
    {
        $author = $this->createUser('alice');
        $topic = $this->createTopic($this->createForum(), $author);
        $this->loginAs($author);
        $crawler = $this->client->request('GET', '/forum/general/'.$topic->getId().'/photos');
        $props = json_decode($crawler->filter('[data-vue-component="PhotoManager"]')->attr('data-props'), true, flags: \JSON_THROW_ON_ERROR);

        // With the token the request reaches the upload itself (which then complains there is no file).
        $this->client->request('POST', $props['uploadUrl'], ['_token' => $props['token']]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, $this->em()->getRepository(PhotoTopic::class)->count([]));
    }

    public function testLogoutNeedsTheTokenOfTheMastheadLink(): void
    {
        $this->loginAs($this->createUser('alice'));

        $this->client->request('GET', '/logout');

        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/gens/alice/config');
        self::assertResponseIsSuccessful('still logged in');

        $this->logOut();

        self::assertResponseRedirects('/');
        $this->client->request('GET', '/gens/alice/config');
        self::assertResponseRedirects('/login');
    }

    public function testALogoutLinkFollowedFromAnotherSiteIsRefused(): void
    {
        $this->loginAs($this->createUser('alice'));
        $crawler = $this->client->request('GET', '/');
        $href = $crawler->filter('.masthead-logout')->attr('href');

        $this->client->request('GET', $href, server: ['HTTP_SEC_FETCH_SITE' => 'cross-site']);

        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/gens/alice/config');
        self::assertResponseIsSuccessful('still logged in');
    }

    public function testAReplyWithoutTheFormTokenIsNotPosted(): void
    {
        $alice = $this->createUser('alice');
        $topic = $this->createTopic($this->createForum(), $alice);
        $this->loginAs($alice);

        $this->client->request('POST', '/forum/general/'.$topic->getId().'/comment', ['comment' => ['content' => '<p>Forgé</p>']], server: ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ctso_comment'));
    }
}
