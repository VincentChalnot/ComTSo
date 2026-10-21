<?php

namespace App\Admin\Filter;

use Sidus\DoctrineFilterBundle\Factory\DoctrineQueryHandlerFactory;
use Sidus\DoctrineFilterBundle\Query\DoctrineQueryContext;
use Sidus\FilterBundle\Criterion\Criterion;
use Sidus\FilterBundle\Criterion\Native;
use Sidus\FilterBundle\Definition\FilterDefinition;
use Sidus\FilterBundle\Filter\NormalizedValue;
use Sidus\FilterBundle\Filter\Type\AbstractFilterType;
use Sidus\FilterBundle\Input\Violation;

/**
 * Filter type `admin_role` of the member list on ROLE_ADMIN: `admin` = administrators, `member` = everybody else. The `roles`
 * column is a JSON array of strings, so the role is looked for as a quoted string in a native DQL condition
 * (its form field is {@see AdminRoleFormMapper}).
 */
class AdminRoleFilterType extends AbstractFilterType
{
    public const ADMIN = 'admin';
    public const MEMBER = 'member';

    public static function getName(): string
    {
        return 'admin_role';
    }

    public static function getRequiredOperations(FilterDefinition $filter): array
    {
        return [Native::class];
    }

    public function normalize(FilterDefinition $filter, mixed $raw): NormalizedValue
    {
        if (self::isBlank($raw)) {
            return NormalizedValue::empty();
        }
        if (!\in_array($raw, [self::ADMIN, self::MEMBER], true)) {
            return NormalizedValue::invalid(new Violation('', 'Expected "admin" or "member".', $raw));
        }

        return NormalizedValue::of($raw);
    }

    public function buildCriterion(FilterDefinition $filter, mixed $value): ?Criterion
    {
        $roles = $filter->attributes[0]->path;

        return new Native(DoctrineQueryHandlerFactory::PROVIDER, static function (DoctrineQueryContext $query) use ($value, $roles): string {
            $column = $query->rootAlias().'.'.$roles;
            $param = $query->parameter('%"ROLE_ADMIN"%');

            return self::ADMIN === $value ? "{$column} LIKE {$param}" : "{$column} NOT LIKE {$param}";
        });
    }
}
