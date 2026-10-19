<?php

namespace App\Tests\Admin;

/**
 * FrankenPHP serves many requests with the same container (worker mode, dev and production). The bundles' datagrid objects are
 * stateful, so the second visit of a list used to fail with "Pager already applied": the registries are request-scoped now.
 */
class WorkerModeTest extends AdminWebTestCase
{
    public function testTheSameListCanBeServedManyTimesByTheSameContainer(): void
    {
        $this->loginAdmin('root');
        $this->createUser('alice');
        $this->client->disableReboot();

        foreach (['/admin/users/', '/admin/users/', '/admin/forums/', '/admin/users/?form[filters][username]=ali', '/admin/users/'] as $url) {
            $crawler = $this->client->request('GET', $url);

            self::assertResponseIsSuccessful($url);
        }

        self::assertSame(2, $crawler->filter('table.table tbody tr')->count(), 'the last visit is not polluted by the filtered one');
    }
}
