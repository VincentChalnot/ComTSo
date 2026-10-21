<?php

namespace App\Tests\Admin;

/**
 * FrankenPHP serves many requests with the same container (worker mode, dev and production). The sidus bundles keep nothing
 * of a request in a service (datagrid, query handler, form and the admin/action are created for each request): every list can
 * be served again and a filtered visit leaves nothing behind.
 */
class WorkerModeTest extends AdminWebTestCase
{
    public function testEveryListCanBeServedManyTimesByTheSameContainer(): void
    {
        $this->loginAdmin('root');
        $this->client->disableReboot();

        foreach (range(1, 3) as $visit) {
            foreach (['users', 'forums', 'topics', 'comments', 'photos', 'quotes'] as $screen) {
                $this->client->request('GET', "/admin/{$screen}/");

                self::assertResponseIsSuccessful("visit {$visit} of /admin/{$screen}/");
            }
        }
    }

    public function testAFilteredVisitDoesNotPolluteTheNextOne(): void
    {
        $this->loginAdmin('root');
        $this->createUser('alice');
        $this->client->disableReboot();

        foreach (['/admin/users/', '/admin/users/?filters[username]=ali', '/admin/users/'] as $url) {
            $crawler = $this->client->request('GET', $url);

            self::assertResponseIsSuccessful($url);
        }

        self::assertSame(2, $crawler->filter('table.table tbody tr')->count(), 'the last visit is not polluted by the filtered one');
    }
}
