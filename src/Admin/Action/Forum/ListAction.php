<?php

namespace App\Admin\Action\Forum;

use App\Admin\Action\ListAction as BaseListAction;
use App\Entity\Forum;
use App\Entity\Topic;
use Doctrine\ORM\EntityManagerInterface;
use Sidus\AdminBundle\Routing\RoutingHelper;
use Sidus\AdminBundle\Templating\TemplatingHelper;
use Sidus\DataGridBundle\Factory\DataGridFactory;
use Sidus\DataGridBundle\Model\DataGrid;
use Symfony\Component\HttpKernel\Attribute\AsController;

/** The forum list, with the topic count of every forum of the page in ONE grouped query (`usage`, keyed by forum id). */
#[AsController]
readonly class ListAction extends BaseListAction
{
    public function __construct(DataGridFactory $dataGridFactory, TemplatingHelper $templatingHelper, RoutingHelper $routingHelper, private EntityManagerInterface $em)
    {
        parent::__construct($dataGridFactory, $templatingHelper, $routingHelper);
    }

    protected function extraViewParameters(DataGrid $dataGrid): array
    {
        $forums = $dataGrid->result->items();
        if ([] === $forums) {
            return ['usage' => []];
        }

        $rows = $this->em->createQuery('SELECT IDENTITY(t.forum) AS forum, COUNT(t.id) AS n FROM '.Topic::class.' t WHERE t.forum IN (:forums) GROUP BY t.forum')
            ->setParameter('forums', array_map(static fn (Forum $forum) => $forum->getId(), $forums))
            ->getArrayResult();

        return ['usage' => array_column($rows, 'n', 'forum')];
    }
}
