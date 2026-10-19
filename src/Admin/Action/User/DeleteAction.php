<?php

namespace App\Admin\Action\User;

use App\Admin\Action\AbstractRemovalAction;
use App\Admin\AdminAccountGuard;
use App\Entity\User;
use App\Forum\UserRemover;
use Sidus\AdminBundle\Form\FormHelper;
use Sidus\AdminBundle\Session\FlashHelper;
use Sidus\AdminBundle\Templating\TemplatingHelper;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Removes a member and what they wrote through {@see UserRemover} (the same code as `app:cutover:prepare --delete-user`). */
#[AsController]
class DeleteAction extends AbstractRemovalAction
{
    public function __construct(
        FormHelper $formHelper,
        TemplatingHelper $templatingHelper,
        FlashHelper $flashHelper,
        private readonly UserRemover $remover,
        private readonly AdminAccountGuard $guard,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($formHelper, $templatingHelper, $flashHelper);
    }

    protected function refusal(object $entity): ?string
    {
        \assert($entity instanceof User);

        return $this->guard->removalProtection($entity);
    }

    protected function details(object $entity): array
    {
        \assert($entity instanceof User);
        $impact = $this->remover->impact($entity);

        return array_values(array_filter(array_map(
            fn (string $kind): ?string => $impact[$kind] > 0 ? $this->translator->trans('admin.user.impact.'.$kind, ['%count%' => $impact[$kind]]) : null,
            array_keys($impact),
        )));
    }

    protected function remove(object $entity): void
    {
        \assert($entity instanceof User);
        $this->remover->remove($entity);
    }
}
