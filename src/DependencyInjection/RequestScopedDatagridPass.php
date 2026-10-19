<?php

namespace App\DependencyInjection;

use App\Admin\Registry\RequestScopedDataGridRegistry;
use App\Admin\Registry\RequestScopedQueryHandlerRegistry;
use Sidus\DataGridBundle\Registry\DataGridRegistry;
use Sidus\FilterBundle\Registry\QueryHandlerRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Swaps the datagrid and query-handler registries of the sidus bundles for the request-scoped ones (see
 * {@see RequestScopedQueryHandlerRegistry}) and has `kernel.reset` call them between two requests of the FrankenPHP worker.
 * The bundles' own definitions (and the configuration calls they carry) are kept, only the class changes.
 */
class RequestScopedDatagridPass implements CompilerPassInterface
{
    private const REPLACEMENTS = [
        QueryHandlerRegistry::class => RequestScopedQueryHandlerRegistry::class,
        DataGridRegistry::class => RequestScopedDataGridRegistry::class,
    ];

    public function process(ContainerBuilder $container): void
    {
        foreach (self::REPLACEMENTS as $id => $class) {
            $container->getDefinition($id)->setClass($class)->addTag('kernel.reset', ['method' => 'reset']);
        }
    }
}
