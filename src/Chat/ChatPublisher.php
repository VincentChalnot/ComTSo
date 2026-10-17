<?php

namespace App\Chat;

use App\Entity\ChatMessage;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\Exception\ExceptionInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Pushes chat events to the Mercure hub. The forum is private: every update is PRIVATE, so the hub only delivers it
 * to subscribers whose authorization cookie (see `ChatController::state()`) grants the topic.
 *
 * - {@see self::TOPIC}: every member receives each new message;
 * - {@see self::userTopic()}: read-pointer changes, delivered only to the tabs/devices of that member.
 */
class ChatPublisher
{
    public const TOPIC = 'chat';

    public function __construct(
        private readonly HubInterface $hub,
        private readonly ChatMessageSerializer $serializer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function userTopic(User|int $user): string
    {
        return self::TOPIC.'/users/'.($user instanceof User ? $user->getId() : $user);
    }

    /** Topics a member is allowed to subscribe to. @return list<string> */
    public static function topicsFor(User $user): array
    {
        return [self::TOPIC, self::userTopic($user)];
    }

    public function publishMessage(ChatMessage $message): void
    {
        $this->publish(new Update(
            self::TOPIC,
            json_encode(['type' => 'message', 'message' => $this->serializer->message($message)], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
            private: true,
        ));
    }

    /** @param array{latestId: int, unread: int} $snapshot see ChatMessageRepository::unreadSnapshot() */
    public function publishRead(User $user, int $lastReadId, array $snapshot): void
    {
        $this->publish(new Update(
            self::userTopic($user),
            json_encode(['type' => 'read', 'lastReadId' => $lastReadId, ...$snapshot], \JSON_THROW_ON_ERROR),
            private: true,
        ));
    }

    /**
     * The message is already stored when we get here: a hub outage must not turn a successful send into an error.
     * Clients catch up through `GET /chat/messages?after=<id>` when they reconnect.
     */
    private function publish(Update $update): void
    {
        try {
            $this->hub->publish($update);
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Chat update could not be published to the Mercure hub.', ['exception' => $e, 'topics' => $update->getTopics()]);
        }
    }
}
