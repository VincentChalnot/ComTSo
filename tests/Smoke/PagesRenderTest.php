<?php

namespace App\Tests\Smoke;

use App\Entity\Photo;
use App\Entity\PhotoTopic;
use App\Tests\Support\AppWebTestCase;

/** Every member-facing page renders (Twig runs with strict_variables in the test env). */
class PagesRenderTest extends AppWebTestCase
{
    public function testMemberPagesRender(): void
    {
        $alice = $this->createUser('alice', ['ROLE_ADMIN']);
        $bob = $this->createUser('bob');
        $forum = $this->createForum();
        $topic = $this->createTopic($forum, $alice, 'Anniversaire !');
        $topic->setContent('<p>Description</p>');
        $comment = $this->createComment($topic, $bob, '<p>Un message avec un <a href="https://example.test">lien</a></p>');
        $photo = (new Photo())->setFilename('x.jpeg')->setOriginalFilename('x.jpg')->setFileSize(1000)->setFileType('image/jpeg')->setAuthor($alice)->setTitle('Une photo');
        $link = (new PhotoTopic())->setTopic($topic)->setPhoto($photo)->setOrder(0);
        $this->em()->persist($photo);
        $this->em()->persist($link);
        $this->em()->flush();

        $this->loginAs($alice);
        $urls = [
            '/', '/?since=2000-01-01', '/forums/', '/forums/general', '/forums/general/nouveau',
            "/forum/general/{$topic->getId()}", "/forum/general/{$topic->getId()}/edit", "/forum/general/{$topic->getId()}/photos", "/forum/general/{$topic->getId()}/preview",
            "/comment/{$comment->getId()}", "/comment/{$comment->getId()}/edit",
            '/gens', '/gens/bob', '/gens/alice', '/gens/alice/parametres/profil', '/gens/alice/parametres/avatar', '/gens/alice/parametres/securite', '/gens/alice/parametres/affichage', '/gens/alice/parametres/statistiques',
            '/photos', "/photos/{$photo->getId()}", "/photos/{$photo->getId()}/edit",
            '/recherche?q=lien', '/recherche?q=x', '/recherche', '/citations/nouvelle',
        ];
        foreach ($urls as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful("GET $url");
        }
    }
}
