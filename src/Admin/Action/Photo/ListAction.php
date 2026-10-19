<?php

namespace App\Admin\Action\Photo;

use App\Admin\Action\ListAction as BaseListAction;
use App\Entity\CommentPhoto;
use App\Entity\Photo;
use App\Entity\PhotoTopic;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Sidus\AdminBundle\DataGrid\DataGridHelper;
use Sidus\AdminBundle\Templating\TemplatingHelper;
use Sidus\DataGridBundle\Model\DataGrid;
use Sidus\FilterBundle\Query\Handler\Doctrine\DoctrineQueryHandlerInterface;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * The photo list. The author is fetched with the photos and the usage of every photo of the page (topics, messages, avatars)
 * comes from three grouped queries: `usage[photoId] = {topics, comments, avatars}`.
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

        $handler->getQueryBuilder()->addSelect('rowAuthor')->leftJoin($handler->getAlias().'.author', 'rowAuthor');
    }

    protected function extraViewParameters(DataGrid $dataGrid): array
    {
        $ids = array_map(static fn (Photo $photo) => $photo->getId(), iterator_to_array($dataGrid->getQueryHandler()->getPager()->getCurrentPageResults()));
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
