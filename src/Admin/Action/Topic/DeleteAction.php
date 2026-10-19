<?php

namespace App\Admin\Action\Topic;

use App\Admin\Action\AbstractRemovalAction;
use App\Entity\Topic;
use App\Forum\TopicRemover;
use Sidus\AdminBundle\Form\FormHelper;
use Sidus\AdminBundle\Session\FlashHelper;
use Sidus\AdminBundle\Templating\TemplatingHelper;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The moderation removal of a whole topic ({@see TopicRemover}, same as the button of the topic page), from the admin list. */
#[AsController]
class DeleteAction extends AbstractRemovalAction
{
    public function __construct(
        FormHelper $formHelper,
        TemplatingHelper $templatingHelper,
        FlashHelper $flashHelper,
        private readonly TopicRemover $remover,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($formHelper, $templatingHelper, $flashHelper);
    }

    protected function details(object $entity): array
    {
        \assert($entity instanceof Topic);

        return [$this->translator->trans('admin.topic.impact', ['%count%' => $entity->getCommentCount()])];
    }

    protected function remove(object $entity): void
    {
        \assert($entity instanceof Topic);
        $this->remover->remove($entity);
    }
}
