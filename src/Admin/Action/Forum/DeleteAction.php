<?php

namespace App\Admin\Action\Forum;

use App\Admin\Action\AbstractRemovalAction;
use App\Entity\Forum;
use App\Forum\ForumRemover;
use Sidus\AdminBundle\Form\FormHelper;
use Sidus\AdminBundle\Session\FlashHelper;
use Sidus\AdminBundle\Templating\TemplatingHelper;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Deletes an empty forum; a forum that still has topics is refused with a message that says how many. */
#[AsController]
readonly class DeleteAction extends AbstractRemovalAction
{
    public function __construct(
        FormHelper $formHelper,
        TemplatingHelper $templatingHelper,
        FlashHelper $flashHelper,
        private ForumRemover $remover,
        private TranslatorInterface $translator,
    ) {
        parent::__construct($formHelper, $templatingHelper, $flashHelper);
    }

    protected function refusal(object $entity): ?string
    {
        \assert($entity instanceof Forum);
        $topics = $this->remover->topicCount($entity);

        return $topics > 0 ? $this->translator->trans('admin.forum.not_empty', ['%count%' => $topics, '%forum%' => $entity->getTitle()]) : null;
    }

    protected function remove(object $entity): void
    {
        \assert($entity instanceof Forum);
        $this->remover->remove($entity);
    }
}
