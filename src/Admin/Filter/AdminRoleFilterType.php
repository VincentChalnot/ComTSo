<?php

namespace App\Admin\Filter;

use Doctrine\ORM\QueryBuilder;
use Sidus\FilterBundle\Doctrine\Filter\Type\AbstractSimpleFilterType;

/**
 * Filter of the member list on ROLE_ADMIN: `admin` = administrators, `member` = everybody else. The `roles` column is a JSON
 * array of strings, so the role is looked for as a quoted string (config/services.yaml gives the type its code and widget).
 */
class AdminRoleFilterType extends AbstractSimpleFilterType
{
    public const ADMIN = 'admin';
    public const MEMBER = 'member';

    protected function applyDQL(QueryBuilder $qb, string $column, $data): string
    {
        $uid = uniqid('role', false);
        $qb->setParameter($uid, '%"ROLE_ADMIN"%');

        return self::ADMIN === $data ? "{$column} LIKE :{$uid}" : "{$column} NOT LIKE :{$uid}";
    }

    protected function isEmpty(mixed $data): bool
    {
        return !\in_array($data, [self::ADMIN, self::MEMBER], true);
    }
}
