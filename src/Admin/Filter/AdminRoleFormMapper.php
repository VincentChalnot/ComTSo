<?php

namespace App\Admin\Filter;

use Sidus\FilterBundle\Definition\FilterDefinition;
use Sidus\FilterBundle\Definition\ListDefinition;
use Sidus\FilterBundle\Form\Mapper\AbstractFormFilterMapper;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;

/** The select of the `admin_role` filter; its choices and placeholder come from the `form.options` of the configuration. */
class AdminRoleFormMapper extends AbstractFormFilterMapper
{
    public function getFilterType(): string
    {
        return AdminRoleFilterType::getName();
    }

    public function add(FormBuilderInterface $builder, FilterDefinition $filter, ListDefinition $list, mixed $data): void
    {
        $this->addField($builder, $filter, ChoiceType::class, [
            'choices' => ['admin' => AdminRoleFilterType::ADMIN, 'member' => AdminRoleFilterType::MEMBER],
        ], $data);
    }
}
