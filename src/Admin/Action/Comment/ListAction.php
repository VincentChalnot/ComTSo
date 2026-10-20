<?php

namespace App\Admin\Action\Comment;

use App\Admin\Action\ListAction as BaseListAction;
use App\Entity\Comment;
use App\Entity\CommentPhoto;
use Doctrine\ORM\EntityManagerInterface;
use Sidus\AdminBundle\DataGrid\DataGridHelper;
use Sidus\AdminBundle\Templating\TemplatingHelper;
use Sidus\DataGridBundle\Model\DataGrid;
use Sidus\FilterBundle\Query\Handler\Doctrine\DoctrineQueryHandlerInterface;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * The message list: topic and author are fetched with the messages, and the number of photos attached to every message of the
 * page comes from ONE grouped query (`usage[commentId]`): no query per row.
 */
#[AsController]
class ListAction extends BaseListAction
{
    public function __construct(DataGridHelper $dataGridHelper, TemplatingHelper $templatingHelper, private readonly EntityManagerInterface $em)
    {
        parent::__construct($dataGridHelper, $templatingHelper);
    }

    protected function prepareQuery(DataGrid $dataGrid): void
    {
        $handler = $dataGrid->getQueryHandler();
        \assert($handler instanceof DoctrineQueryHandlerInterface);

        $handler->getQueryBuilder()
            ->addSelect('rowTopic', 'rowAuthor')
            ->leftJoin($handler->getAlias().'.topic', 'rowTopic')
            ->leftJoin($handler->getAlias().'.author', 'rowAuthor');
    }

    protected function extraViewParameters(DataGrid $dataGrid): array
    {
        $ids = array_map(static fn (Comment $comment) => $comment->getId(), iterator_to_array($dataGrid->getQueryHandler()->getPager()->getCurrentPageResults()));
        if ([] === $ids) {
            return ['usage' => []];
        }

        $rows = $this->em->createQuery('SELECT IDENTITY(cp.comment) AS comment, COUNT(cp.id) AS n FROM '.CommentPhoto::class.' cp WHERE cp.comment IN (:ids) GROUP BY cp.comment')
            ->setParameter('ids', $ids)
            ->getArrayResult();

        return ['usage' => array_column($rows, 'n', 'comment')];
    }
}
