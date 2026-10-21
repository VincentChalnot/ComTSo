<?php

namespace App\Admin\Action\Comment;

use App\Admin\Action\AbstractRemovalAction;
use App\Entity\Comment;
use App\Forum\CommentRemover;
use Sidus\AdminBundle\Form\FormHelper;
use Sidus\AdminBundle\Session\FlashHelper;
use Sidus\AdminBundle\Templating\TemplatingHelper;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The moderation removal of one message ({@see CommentRemover}, same as the button on the topic page), from the admin list. */
#[AsController]
readonly class DeleteAction extends AbstractRemovalAction
{
    public function __construct(
        FormHelper $formHelper,
        TemplatingHelper $templatingHelper,
        FlashHelper $flashHelper,
        private CommentRemover $remover,
        private TranslatorInterface $translator,
    ) {
        parent::__construct($formHelper, $templatingHelper, $flashHelper);
    }

    protected function details(object $entity): array
    {
        \assert($entity instanceof Comment);

        return [
            $this->translator->trans('admin.comment.impact_where', ['%author%' => $entity->getAuthor()?->getUsername() ?? '—', '%topic%' => $entity->getTopic()->getTitle()]),
            $this->translator->trans('admin.comment.impact_photos', ['%count%' => $entity->getPhotos()->count()]),
        ];
    }

    protected function remove(object $entity): void
    {
        \assert($entity instanceof Comment);
        $this->remover->remove($entity);
    }
}
