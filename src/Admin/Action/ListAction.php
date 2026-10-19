<?php

namespace App\Admin\Action;

use Sidus\AdminBundle\Action\ActionInjectableInterface;
use Sidus\AdminBundle\Action\ActionInjectableTrait;
use Sidus\AdminBundle\DataGrid\DataGridHelper;
use Sidus\AdminBundle\Request\ActionResponseInterface;
use Sidus\AdminBundle\Templating\TemplatingHelper;
use Sidus\DataGridBundle\Model\DataGrid;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * Every list of the admin panel (`controller_pattern` of config/packages/sidus.yaml): the bundle's ListAction plus one guard.
 *
 * The sort column of a datagrid travels in a hidden field (`form[sortable][config][column]`) that the filter bundle resolves to
 * a Doctrine attribute path WITHOUT checking it against the configured `sortable` list: any property of the entity (a
 * password hash, an e-mail address) could be used as ORDER BY, which leaks how the hidden values compare. Anything that
 * is not an allowed column is replaced here by the configured default sort (a request without any sort gets it explicitly too:
 * an absent column would mean no ORDER BY at all).
 */
#[AsController]
class ListAction implements ActionInjectableInterface
{
    use ActionInjectableTrait;

    public function __construct(
        private readonly DataGridHelper $dataGridHelper,
        private readonly TemplatingHelper $templatingHelper,
    ) {
    }

    public function __invoke(Request $request): ActionResponseInterface
    {
        $dataGrid = $this->dataGridHelper->buildDataGridForm($this->action, $request);
        $this->prepareQuery($dataGrid);
        $this->restrictSortColumn($request, $dataGrid);
        $dataGrid->handleRequest($request);

        return $this->templatingHelper->renderListAction($this->action, $dataGrid, $this->extraViewParameters($dataGrid));
    }

    /** Called before the request is applied: a screen adds the joins/selects of the columns it shows here (no query per row). */
    protected function prepareQuery(DataGrid $dataGrid): void
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

    private function restrictSortColumn(Request $request, DataGrid $dataGrid): void
    {
        $configuration = $dataGrid->getQueryHandler()->getConfiguration();
        // The datagrid form is a named form (`form[sortable][config][column]`): everything lives under its name.
        $name = $dataGrid->getForm()->getName();
        $submitted = $request->query->all($name);
        $sort = $submitted['sortable'] ?? [];
        $config = \is_array($sort) ? ($sort['config'] ?? []) : [];
        $column = \is_array($config) ? ($config['column'] ?? null) : null;
        if (\is_string($column) && \in_array($column, $configuration->getSortable(), true)) {
            return;
        }

        $default = $configuration->getDefaultSort();
        $defaultColumn = array_key_first($default);
        $submitted['sortable'] = [...(\is_array($sort) ? $sort : []), 'config' => null === $defaultColumn ? [] : [
            'column' => (string) $defaultColumn,
            'direction' => 'desc' === strtolower((string) $default[$defaultColumn]) ? '1' : '0',
        ]];
        $request->query->set($name, $submitted);
    }
}
