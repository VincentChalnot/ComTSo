<?php

namespace App\Admin\Action\Topic;

use App\Admin\Action\ListAction as BaseListAction;
use Sidus\DataGridBundle\Model\DataGrid;
use Sidus\FilterBundle\Query\Handler\Doctrine\DoctrineQueryHandlerInterface;
use Symfony\Component\HttpKernel\Attribute\AsController;

/** The topic list: forum and author are fetched with the topics (one query for the page, not one per row). */
#[AsController]
class ListAction extends BaseListAction
{
    protected function prepareQuery(DataGrid $dataGrid): void
    {
        $handler = $dataGrid->getQueryHandler();
        \assert($handler instanceof DoctrineQueryHandlerInterface);

        $handler->getQueryBuilder()
            ->addSelect('rowForum', 'rowAuthor')
            ->leftJoin($handler->getAlias().'.forum', 'rowForum')
            ->leftJoin($handler->getAlias().'.author', 'rowAuthor');
    }
}
