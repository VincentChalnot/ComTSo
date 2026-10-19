<?php

namespace App;

use App\DependencyInjection\RequestScopedDatagridPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        // The sidus datagrid registries hold stateful objects: see the pass.
        $container->addCompilerPass(new RequestScopedDatagridPass());
    }
}
