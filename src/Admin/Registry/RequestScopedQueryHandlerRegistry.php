<?php

namespace App\Admin\Registry;

use Sidus\FilterBundle\Query\Handler\QueryHandlerInterface;
use Sidus\FilterBundle\Registry\QueryHandlerRegistry;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The filter bundle keeps every query handler it builds for the whole life of the process, but a handler is STATEFUL (form,
 * sort configuration, pager: handling a second request throws "Pager already applied"). With the classic one-process-per-request
 * PHP that never shows; FrankenPHP runs the app as a long-lived worker (dev and production), where the second visit of any
 * admin list would fail. Here a handler is built per request: `kernel.reset` (registered by {@see \App\DependencyInjection\RequestScopedDatagridPass})
 * forgets the built ones, and the configuration they are built from is kept instead of being consumed.
 */
class RequestScopedQueryHandlerRegistry extends QueryHandlerRegistry implements ResetInterface
{
    protected function buildQueryHandler(string $code): QueryHandlerInterface
    {
        $configuration = $this->getQueryHandlerConfiguration($code);
        $queryHandler = $this->getQueryHandlerFactory($configuration->getProvider())->createQueryHandler($configuration);
        $this->queryHandlers[$code] = $queryHandler;

        return $queryHandler;
    }

    public function reset(): void
    {
        $this->queryHandlers = [];
    }
}
