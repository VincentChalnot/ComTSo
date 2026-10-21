<?php

namespace App\Admin\Action\Comment;

use App\Admin\Action\ListAction as BaseListAction;
use App\Entity\Comment;
use App\Entity\CommentPhoto;
use Doctrine\ORM\EntityManagerInterface;
use Sidus\AdminBundle\Routing\RoutingHelper;
use Sidus\AdminBundle\Templating\TemplatingHelper;
use Sidus\DataGridBundle\Factory\DataGridFactory;
use Sidus\DataGridBundle\Model\DataGrid;
use Sidus\DoctrineFilterBundle\Query\DoctrineQueryContext;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * The message list: topic and author are fetched with the messages, and the number of photos attached to every message of the
 * page comes from ONE grouped query (`usage[commentId]`): no query per row.
 */
#[AsController]
readonly class ListAction extends BaseListAction
{
    public function __construct(DataGridFactory $dataGridFactory, TemplatingHelper $templatingHelper, RoutingHelper $routingHelper, private EntityManagerInterface $em)
    {
        parent::__construct($dataGridFactory, $templatingHelper, $routingHelper);
    }

    protected function prepareQuery(DoctrineQueryContext $query): void
    {
        $query->queryBuilder()->addSelect($query->joins()->aliasFor('topic'), $query->joins()->aliasFor('author'));
    }

    protected function extraViewParameters(DataGrid $dataGrid): array
    {
        $ids = array_map(static fn (Comment $comment) => $comment->getId(), $dataGrid->result->items());
        if ([] === $ids) {
            return ['usage' => []];
        }

        $rows = $this->em->createQuery('SELECT IDENTITY(cp.comment) AS comment, COUNT(cp.id) AS n FROM '.CommentPhoto::class.' cp WHERE cp.comment IN (:ids) GROUP BY cp.comment')
            ->setParameter('ids', $ids)
            ->getArrayResult();

        return ['usage' => array_column($rows, 'n', 'comment')];
    }
}
