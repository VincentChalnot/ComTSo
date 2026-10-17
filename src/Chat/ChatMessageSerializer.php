<?php

namespace App\Chat;

use App\Entity\ChatMessage;
use App\Entity\User;
use App\Twig\LayoutExtension;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** JSON shape of chat messages and members shared by the HTTP endpoints, the Mercure updates and the page. */
class ChatMessageSerializer
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly LayoutExtension $layout,
    ) {
    }

    /**
     * @return array{id: int|null, author: array{id: int|null, username: string, avatarUrl: string|null, hue: int}|null, content: string, createdAt: string}
     */
    public function message(ChatMessage $message): array
    {
        return [
            'id' => $message->getId(),
            'author' => $message->getAuthor() ? $this->member($message->getAuthor()) : null,
            'content' => (string) $message->getContent(),
            'createdAt' => $message->getCreatedAt()?->format(\DATE_ATOM) ?? '',
        ];
    }

    /**
     * @param iterable<ChatMessage> $messages
     *
     * @return list<array<string, mixed>>
     */
    public function messages(iterable $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            $out[] = $this->message($message);
        }

        return $out;
    }

    /** @return array{id: int|null, username: string, avatarUrl: string|null, hue: int} */
    public function member(User $user): array
    {
        $avatar = $user->getAvatar();

        return [
            'id' => $user->getId(),
            'username' => $user->getUsername(),
            'avatarUrl' => $avatar ? $this->urls->generate('photo_variant', ['id' => $avatar->getId(), 'variant' => 'avatar']) : null,
            // Same hue as the `user_hue()` Twig function, so the placeholder avatar looks the same everywhere.
            'hue' => $this->layout->userHue($user),
        ];
    }

    /**
     * @param iterable<User> $users
     *
     * @return list<array<string, mixed>>
     */
    public function members(iterable $users): array
    {
        $out = [];
        foreach ($users as $user) {
            $out[] = $this->member($user);
        }

        return $out;
    }
}
