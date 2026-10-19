<?php

namespace App\Admin\Registry;

use Sidus\DataGridBundle\Model\DataGrid;
use Sidus\DataGridBundle\Registry\DataGridRegistry;
use Symfony\Contracts\Service\ResetInterface;
use UnexpectedValueException;

/**
 * Same idea as {@see RequestScopedQueryHandlerRegistry}: a datagrid owns a query handler and its form view, so one is built per
 * request from the configuration, which this registry keeps (the bundle's registry drops it after the first build).
 */
class RequestScopedDataGridRegistry extends DataGridRegistry implements ResetInterface
{
    /** @var array<string, array> raw configurations as the container gave them */
    private array $sources = [];

    public function addRawDataGridConfiguration(string $code, array $configuration): void
    {
        $this->sources[$code] = $configuration;
        parent::addRawDataGridConfiguration($code, $configuration);
    }

    protected function buildDataGrid(string $code): DataGrid
    {
        if (!isset($this->sources[$code])) {
            throw new UnexpectedValueException("No data-grid with code : {$code}");
        }

        $configuration = $this->sources[$code];
        if (!$this->queryHandlerRegistry->hasQueryHandler('__sidus_datagrid.'.$code)) {
            $this->queryHandlerRegistry->addRawQueryHandlerConfiguration('__sidus_datagrid.'.$code, $configuration['query_handler']);
        }
        $configuration['query_handler'] = $this->queryHandlerRegistry->getQueryHandler('__sidus_datagrid.'.$code);

        $dataGrid = new DataGrid($code, $configuration);
        $this->addDataGrid($dataGrid);

        return $dataGrid;
    }

    public function reset(): void
    {
        $this->dataGrids = [];
    }
}
