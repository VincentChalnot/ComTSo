<?php

namespace App\Admin\Action\Photo;

use App\Admin\Action\ListAction as BaseListAction;
use App\Entity\CommentPhoto;
use App\Entity\Photo;
use App\Entity\PhotoTopic;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Sidus\AdminBundle\Routing\RoutingHelper;
use Sidus\AdminBundle\Templating\TemplatingHelper;
use Sidus\DataGridBundle\Factory\DataGridFactory;
use Sidus\DataGridBundle\Model\DataGrid;
use Sidus\DoctrineFilterBundle\Query\DoctrineQueryContext;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * The photo list. The author is fetched with the photos and the usage of every photo of the page (topics, messages, avatars)
 * comes from three grouped queries: `usage[photoId] = {topics, comments, avatars}`.
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
        $query->queryBuilder()->addSelect($query->joins()->aliasFor('author'));
    }

    protected function extraViewParameters(DataGrid $dataGrid): array
    {
        $ids = array_map(static fn (Photo $photo) => $photo->getId(), $dataGrid->result->items());
        $usage = array_fill_keys($ids, ['topics' => 0, 'comments' => 0, 'avatars' => 0]);
        if ([] === $ids) {
            return ['usage' => $usage];
        }

        foreach (['topics' => [PhotoTopic::class, 'photo'], 'comments' => [CommentPhoto::class, 'photo'], 'avatars' => [User::class, 'avatar']] as $kind => [$class, $field]) {
            $rows = $this->em->createQuery('SELECT IDENTITY(x.'.$field.') AS photo, COUNT(x.id) AS n FROM '.$class.' x WHERE x.'.$field.' IN (:ids) GROUP BY x.'.$field)
                ->setParameter('ids', $ids)
                ->getArrayResult();
            foreach ($rows as $row) {
                $usage[(int) $row['photo']][$kind] = (int) $row['n'];
            }
        }

        return ['usage' => $usage];
    }
}
