<?php

namespace App\Admin\Action;

use Sidus\AdminBundle\Action\ListAction as BaseListAction;
use Sidus\AdminBundle\Model\Action;
use Sidus\AdminBundle\Request\ActionResponseInterface;
use Sidus\DataGridBundle\Model\DataGrid;
use Sidus\DoctrineFilterBundle\Query\DoctrineQueryContext;
use Sidus\DoctrineFilterBundle\Query\DoctrineQueryHandler;
use Sidus\FilterBundle\Provider\QueryHandlerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * Every list of the admin panel (`controller_pattern` of config/packages/sidus.yaml): the bundle's ListAction plus two hooks
 * for a screen: {@see prepareQuery()} (the joins/selects of the columns it shows, so that there is no query per row) and
 * {@see extraViewParameters()} (data of the page's rows loaded in one grouped query).
 *
 * The sort a request can ask for is structural (the sort input names a column key of the datagrid, never a property path),
 * so unlike the old bundles nothing has to be re-checked here.
 */
#[AsController]
readonly class ListAction extends BaseListAction
{
    public function __invoke(Request $request, Action $action): ActionResponseInterface
    {
        $dataGrid = $this->dataGridFactory->create(
            $this->getDataGridCode($action),
            $request,
            ['action' => $this->routingHelper->getCurrentUri($action, $request)],
            fn (QueryHandlerInterface $handler): QueryHandlerInterface => $this->customizeHandler($handler, $request, $action),
        );

        return $this->templatingHelper->renderListAction($action, $dataGrid, $this->extraViewParameters($dataGrid));
    }

    protected function customizeHandler(QueryHandlerInterface $handler, Request $request, Action $action): QueryHandlerInterface
    {
        \assert($handler instanceof DoctrineQueryHandler);

        return $handler->withCustomizer($this->prepareQuery(...));
    }

    /** Runs on the fresh query of the request, before the filters are applied. */
    protected function prepareQuery(DoctrineQueryContext $query): void
    {
    }

    /**
     * Variables the list template of one screen needs besides the datagrid (e.g. a batch-loaded column): override.
     *
     * @return array<string, mixed>
     */
    protected function extraViewParameters(DataGrid $dataGrid): array
    {
        return [];
    }
}
