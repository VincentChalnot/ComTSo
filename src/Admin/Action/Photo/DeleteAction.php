<?php

namespace App\Admin\Action\Photo;

use App\Admin\Action\AbstractRemovalAction;
use App\Entity\Photo;
use App\Photo\PhotoRemover;
use App\Repository\PhotoRepository;
use Sidus\AdminBundle\Form\FormHelper;
use Sidus\AdminBundle\Session\FlashHelper;
use Sidus\AdminBundle\Templating\TemplatingHelper;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The administrator's photo deletion ({@see PhotoRemover}, same as the button of the photo page): the files go with the row. */
#[AsController]
class DeleteAction extends AbstractRemovalAction
{
    public function __construct(
        FormHelper $formHelper,
        TemplatingHelper $templatingHelper,
        FlashHelper $flashHelper,
        private readonly PhotoRemover $remover,
        private readonly PhotoRepository $photos,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($formHelper, $templatingHelper, $flashHelper);
    }

    protected function details(object $entity): array
    {
        \assert($entity instanceof Photo);

        return [$this->translator->trans($this->photos->isReferenced($entity) ? 'admin.photo.impact_used' : 'admin.photo.impact_unused')];
    }

    protected function remove(object $entity): void
    {
        \assert($entity instanceof Photo);
        $this->remover->remove($entity);
    }
}
