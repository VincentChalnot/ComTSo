<?php

namespace App\Tests\Admin;

use Sidus\AdminBundle\Configuration\AdminRegistry;
use Sidus\DataGridBundle\Registry\DataGridRegistry;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The admin panel is French: no column head, filter label, screen title or flash may fall back to the bundle's humanized English
 * property names. Adding a screen or a column without its translations fails here.
 */
class AdminTranslationsTest extends AdminWebTestCase
{
    public function testEveryLabelOfEveryScreenIsTranslated(): void
    {
        $container = static::getContainer();
        $catalogue = $container->get(TranslatorInterface::class);
        \assert($catalogue instanceof TranslatorBagInterface);
        $messages = $catalogue->getCatalogue('fr');
        $missing = [];
        $need = static function (string $key) use ($messages, &$missing): void {
            if (!$messages->has($key)) {
                $missing[] = $key;
            }
        };

        $admins = $container->get(AdminRegistry::class)->getAdmins();
        self::assertNotEmpty($admins);
        foreach ($admins as $admin) {
            $code = $admin->getCode();
            foreach (['title', 'description'] as $key) {
                $need("admin.$code.$key");
            }
            if ($admin->hasAction('create')) {
                $need("admin.$code.new");
            }
            if ($admin->hasAction('edit')) {
                $need("admin.$code.edit");
            }
            if ($admin->hasAction('delete')) {
                $need("admin.$code.delete");
                $need("admin.$code.delete_confirm");
            }
            foreach (array_keys($admin->getActions()) as $action) {
                if (in_array($action, ['create', 'edit', 'delete'], true)) {
                    // the bundle's flash helper looks for the screen's key first, then the generic one (which exists too)
                    $need("sidus.admin.$code.$action.success");
                }
            }

            $grid = $container->get(DataGridRegistry::class)->getDataGrid($code);
            foreach ($grid->getColumns() as $column) {
                $need("datagrid.$code.{$column->getCode()}");
            }
            foreach ($grid->getQueryHandler()->getConfiguration()->getFilters() as $filter) {
                self::assertNotNull($filter->getLabel(), "filter $code.{$filter->getCode()} has no label");
                $need($filter->getLabel());
            }
        }

        self::assertSame([], $missing, 'missing French translations (translations/messages.fr.yaml)');
    }
}
