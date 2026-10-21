<?php

namespace App\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Sidus\AdminBundle\Registry\AdminRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Landing page of the admin panel: one card per screen configured in config/packages/sidus.yaml, in configuration order.
 * The screens themselves are bundle actions (route names `sidus_admin.<screen>.<action>`).
 */
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    #[Route('/admin', name: 'admin_index', methods: ['GET'])]
    public function __invoke(AdminRegistry $admins, ManagerRegistry $doctrine): Response
    {
        $screens = [];
        foreach ($admins->all() as $admin) {
            $screens[] = [
                'admin' => $admin,
                'count' => $doctrine->getRepository((string) $admin->getEntity())->count([]),
            ];
        }

        return $this->render('admin/index.html.twig', ['screens' => $screens]);
    }
}
