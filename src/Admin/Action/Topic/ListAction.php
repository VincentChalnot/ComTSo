<?php

namespace App\Admin\Action\Topic;

use App\Admin\Action\ListAction as BaseListAction;
use Sidus\DoctrineFilterBundle\Query\DoctrineQueryContext;
use Symfony\Component\HttpKernel\Attribute\AsController;

/** The topic list: forum and author are fetched with the topics (one query for the page, not one per row). */
#[AsController]
readonly class ListAction extends BaseListAction
{
    protected function prepareQuery(DoctrineQueryContext $query): void
    {
        $query->queryBuilder()->addSelect($query->joins()->aliasFor('forum'), $query->joins()->aliasFor('author'));
    }
}
