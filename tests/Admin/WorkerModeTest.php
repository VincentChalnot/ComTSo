<?php

namespace App\Tests\Admin;

/**
 * FrankenPHP serves many requests with the same container (worker mode, dev and production). The sidus registries cache the
 * datagrid and query handler they build (stateful: form, sort, pager) in a shared service and never reset it, so the second
 * visit of a list used to fail with "Pager already applied": the registries are request-scoped now (`RequestScopedDatagridPass`).
 */
class WorkerModeTest extends AdminWebTestCase
{
    public function testEveryListCanBeServedManyTimesByTheSameContainer(): void
    {
        $this->loginAdmin('root');
        $this->client->disableReboot();

        foreach (range(1, 3) as $visit) {
            foreach (['users', 'forums', 'topics', 'quotes', 'photos'] as $screen) {
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

        foreach (['/admin/users/', '/admin/users/?form[filters][username]=ali', '/admin/users/'] as $url) {
            $crawler = $this->client->request('GET', $url);

            self::assertResponseIsSuccessful($url);
        }

        self::assertSame(2, $crawler->filter('table.table tbody tr')->count(), 'the last visit is not polluted by the filtered one');
    }
}
