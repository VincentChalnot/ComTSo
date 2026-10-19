<?php

namespace App\Admin\Action;

use Sidus\AdminBundle\Action\ActionInjectableInterface;
use Sidus\AdminBundle\Action\ActionInjectableTrait;
use Sidus\AdminBundle\Attribute\AdminEntity;
use Sidus\AdminBundle\Form\FormHelper;
use Sidus\AdminBundle\Request\ActionResponseInterface;
use Sidus\AdminBundle\Request\RedirectActionResponse;
use Sidus\AdminBundle\Session\FlashHelper;
use Sidus\AdminBundle\Templating\TemplatingHelper;
use Symfony\Component\HttpFoundation\Request;

/**
 * A destructive admin action done right: a confirmation page on GET (it changes nothing), the removal on POST only,
 * through the empty Symfony form of the bundle, whose token is the CSRF protection (a POST without it re-renders the
 * confirmation page and removes nothing). The removal itself is domain logic (files, counters, dependent rows), never a
 * raw EntityManager::remove(): subclasses call the service that owns it.
 *
 * To use one, point a screen's `delete` action at it with `controller_pattern` (App\Admin\Action\<Screen>\DeleteAction).
 */
abstract class AbstractRemovalAction implements ActionInjectableInterface
{
    use ActionInjectableTrait;

    public function __construct(
        private readonly FormHelper $formHelper,
        private readonly TemplatingHelper $templatingHelper,
        private readonly FlashHelper $flashHelper,
    ) {
    }

    public function __invoke(
        Request $request,
        #[AdminEntity]
        object $data,
    ): ActionResponseInterface {
        $session = $request->getSession();
        if (null !== $reason = $this->refusal($data)) {
            $session->getFlashBag()->add('error', $reason);

            return $this->backToList();
        }

        $form = $this->formHelper->getEmptyForm($this->action, $request);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->remove($data);
            $this->flashHelper->addFlash($this->action, $session);

            return $this->backToList();
        }

        return $this->templatingHelper->renderFormAction($this->action, $form, $data, ['details' => $this->details($data)]);
    }

    /** @return string|null why the entity must not be removed (French, shown as an error flash), null when it may be */
    protected function refusal(object $entity): ?string
    {
        return null;
    }

    /** @return list<string> what the removal takes along, shown on the confirmation page (French sentences) */
    protected function details(object $entity): array
    {
        return [];
    }

    abstract protected function remove(object $entity): void;

    private function backToList(): ActionResponseInterface
    {
        return new RedirectActionResponse($this->action->getAdmin()->getAction($this->action->getOption('redirect_action', 'list')));
    }
}
