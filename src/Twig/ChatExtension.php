<?php

namespace App\Twig;

use App\Entity\User;
use App\Repository\ChatMessageRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Attribute\AsTwigFunction;

/**
 * Feeds the unread-messages badge of the "Chat" entry of the navigation bar. `assets/scripts/chat-badge.js` keeps it
 * live afterwards.
 */
class ChatExtension
{
    private ?int $unread = null;

    public function __construct(
        private readonly ChatMessageRepository $messages,
        private readonly Security $security,
        private readonly RequestStack $requests,
    ) {
    }

    /** Messages of other members the user has not seen yet; never shown (0) on the chat page itself. */
    #[AsTwigFunction('chat_unread_count')]
    public function unreadCount(): int
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || 'chat_index' === $this->requests->getCurrentRequest()?->attributes->get('_route')) {
            return 0;
        }

        return $this->unread ??= $this->messages->countUnreadFor($user);
    }
}
