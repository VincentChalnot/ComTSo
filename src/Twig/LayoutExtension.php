<?php

namespace App\Twig;

use App\Entity\Forum;
use App\Entity\Quote;
use App\Entity\Topic;
use App\Entity\User;
use App\Repository\ForumRepository;
use App\Repository\MessageRepository;
use App\Repository\QuoteRepository;
use App\Repository\TopicRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFunction;

/** Data needed by the global layout (navigation, sidebar), computed lazily and at most once per request. */
class LayoutExtension
{
    /** @var list<Forum>|null */
    private ?array $forums = null;
    private ?Quote $quote = null;
    private bool $quoteLoaded = false;
    /** @var array<int, int>|null */
    private ?array $unread = null;

    public function __construct(
        private readonly QuoteRepository $quotes,
        private readonly ForumRepository $forumRepository,
        private readonly TopicRepository $topics,
        private readonly Security $security,
        private readonly MessageRepository $messages,
    ) {
    }

    #[AsTwigFunction('random_quote')]
    public function randomQuote(): ?Quote
    {
        if (!$this->quoteLoaded) {
            $this->quote = $this->quotes->findRandom();
            $this->quoteLoaded = true;
        }

        return $this->quote;
    }

    /** @return list<Forum> */
    #[AsTwigFunction('nav_forums')]
    public function navForums(): array
    {
        return $this->forums ??= $this->forumRepository->findAllOrdered();
    }

    /** @return list<Topic> favourite topics of the current user, most recently active first */
    #[AsTwigFunction('favorite_topics')]
    public function favoriteTopics(int $limit = 12): array
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->topics->findFavoritesOf($user, $limit) : [];
    }

    /** @return array<int, int> unread private messages of the current member, per sender id */
    #[AsTwigFunction('unread_messages')]
    public function unreadMessages(): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }

        return $this->unread ??= $this->messages->unreadCountsBySender($user);
    }

    /** Stable per-user hue used for the placeholder avatar. */
    #[AsTwigFunction('user_hue')]
    public function userHue(User $user): int
    {
        return crc32(mb_strtolower($user->getUsername())) % 360;
    }
}
