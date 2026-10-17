<?php

namespace App\Controller;

use App\Chat\ChatMessageSerializer;
use App\Chat\ChatPublisher;
use App\Chat\OnlineMembers;
use App\Entity\ChatMessage;
use App\Entity\User;
use App\Repository\ChatMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The chat: one page (`/chat`, a Vue widget) and a small JSON API. Real time goes through a private Mercure hub:
 * `state()` hands every logged-in member a subscriber cookie, `send()`/`read()` publish private updates.
 * Chat messages are PLAIN TEXT, never HTML.
 */
#[IsGranted('ROLE_USER')]
class ChatController extends AbstractController
{
    public const MAX_LENGTH = 2000;
    public const PAGE_SIZE = 50;
    public const CATCH_UP_SIZE = 200;
    public const CSRF_ID = 'chat';

    public function __construct(
        private readonly ChatMessageRepository $messages,
        private readonly ChatMessageSerializer $serializer,
        private readonly ChatPublisher $publisher,
    ) {
    }

    #[Route('/chat', name: 'chat_index', methods: ['GET'])]
    public function index(OnlineMembers $online, CsrfTokenManagerInterface $csrf): Response
    {
        $user = $this->member();
        $page = $this->messages->findBefore(null, self::PAGE_SIZE);

        return $this->render('chat/index.html.twig', [
            'props' => [
                'messages' => $this->serializer->messages($page['messages']),
                'hasMore' => $page['hasMore'],
                'me' => $this->serializer->member($user),
                'lastReadId' => $user->getChatLastReadId(),
                'online' => $this->serializer->members($online->list()),
                'maxLength' => self::MAX_LENGTH,
                'csrf' => $csrf->getToken(self::CSRF_ID)->getValue(),
                'urls' => [
                    'messages' => $this->generateUrl('chat_messages'),
                    'send' => $this->generateUrl('chat_send'),
                    'read' => $this->generateUrl('chat_read'),
                    'online' => $this->generateUrl('chat_online'),
                ],
            ],
        ]);
    }

    /** Old bookmarks of the legacy chat (`/chat/`): the canonical URL has no trailing slash. */
    #[Route('/chat/', name: 'chat_index_legacy', methods: ['GET'])]
    public function legacyIndex(): Response
    {
        return $this->redirectToRoute('chat_index', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    /**
     * Everything the nav badge script (loaded on every page) needs to connect to the hub, and the subscriber
     * authorization cookie that lets it receive the PRIVATE updates: only logged-in members can get here.
     * Also used to resynchronise the badge after a reconnection.
     */
    #[Route('/chat/state', name: 'chat_state', methods: ['GET'])]
    public function state(Request $request, Authorization $authorization, HubInterface $hub): JsonResponse
    {
        $user = $this->member();
        $topics = ChatPublisher::topicsFor($user);
        $authorization->setCookie($request, $topics);

        return $this->json([
            'userId' => $user->getId(),
            'lastReadId' => $user->getChatLastReadId(),
            ...$this->messages->unreadSnapshot($user),
            // Path only: the hub is on the same host so the cookie is sent along (EventSource withCredentials).
            'hubUrl' => parse_url($hub->getPublicUrl(), \PHP_URL_PATH) ?: '/.well-known/mercure',
            'topics' => $topics,
        ]);
    }

    /**
     * History: the latest messages, `?before=<id>` for older ones (infinite scroll), `?after=<id>` to catch up after
     * a reconnection. Messages are returned oldest first.
     */
    #[Route('/chat/messages', name: 'chat_messages', methods: ['GET'])]
    public function history(Request $request): JsonResponse
    {
        $before = $this->cursor($request, 'before', 1);
        $after = $this->cursor($request, 'after', 0);
        if (false === $before || false === $after || (null !== $before && null !== $after)) {
            return $this->error('Curseur invalide.', Response::HTTP_BAD_REQUEST);
        }

        $page = null !== $after
            ? $this->messages->findAfter($after, self::CATCH_UP_SIZE)
            : $this->messages->findBefore($before, self::PAGE_SIZE);

        return $this->json(['messages' => $this->serializer->messages($page['messages']), 'hasMore' => $page['hasMore']]);
    }

    #[Route('/chat/online', name: 'chat_online', methods: ['GET'])]
    public function online(OnlineMembers $online): JsonResponse
    {
        return $this->json(['members' => $this->serializer->members($online->list())]);
    }

    #[Route('/chat/send', name: 'chat_send', methods: ['POST'])]
    public function send(Request $request, EntityManagerInterface $em): JsonResponse
    {
        if (!$this->validCsrf($request)) {
            return $this->error('Jeton de sécurité invalide, rechargez la page.', Response::HTTP_FORBIDDEN);
        }

        $content = $this->normalize($request->getPayload()->all()['content'] ?? null);
        if ('' === $content) {
            return $this->error('Le message est vide.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (mb_strlen($content) > self::MAX_LENGTH) {
            return $this->error(sprintf('Le message est trop long (%d caractères maximum).', self::MAX_LENGTH), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $message = (new ChatMessage())->setContent($content)->setAuthor($this->member());
        $em->persist($message);
        $em->flush();

        $this->publisher->publishMessage($message);

        return $this->json($this->serializer->message($message), Response::HTTP_CREATED);
    }

    /** Marks the chat as read up to `lastId` (default: the newest message). Never moves backwards. */
    #[Route('/chat/read', name: 'chat_read', methods: ['POST'])]
    public function read(Request $request): JsonResponse
    {
        if (!$this->validCsrf($request)) {
            return $this->error('Jeton de sécurité invalide, rechargez la page.', Response::HTTP_FORBIDDEN);
        }

        $lastId = $request->getPayload()->all()['lastId'] ?? null;
        if (null === $lastId) {
            $lastId = $this->messages->latestId();
        } elseif (false === ($lastId = filter_var($lastId, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]))) {
            return $this->error('Identifiant de message invalide.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $this->member();
        $moved = $this->messages->markReadUpTo($user, $lastId);
        $snapshot = $this->messages->unreadSnapshot($user);
        if ($moved) {
            // Other tabs/devices of this member converge on the new pointer.
            $this->publisher->publishRead($user, $user->getChatLastReadId(), $snapshot);
        }

        return $this->json(['lastReadId' => $user->getChatLastReadId(), ...$snapshot]);
    }

    private function member(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }

    private function validCsrf(Request $request): bool
    {
        $token = $request->headers->get('X-CSRF-Token') ?? ($request->getPayload()->all()['_token'] ?? null);

        return \is_string($token) && $this->isCsrfTokenValid(self::CSRF_ID, $token);
    }

    /** @return int|false|null the id (at least $min), null when the parameter is absent, false when it is invalid */
    private function cursor(Request $request, string $name, int $min): int|false|null
    {
        if (!$request->query->has($name)) {
            return null;
        }

        return filter_var($request->query->get($name), \FILTER_VALIDATE_INT, ['options' => ['min_range' => $min]]);
    }

    /** Plain text only: unified line breaks, no control characters, trimmed. Anything else is rejected as empty. */
    private function normalize(mixed $raw): string
    {
        if (!\is_string($raw)) {
            return '';
        }

        $text = str_replace(["\r\n", "\r"], "\n", $raw);
        $text = preg_replace('/(?![\n\t])\p{Cc}/u', '', $text);

        return null === $text ? '' : trim($text);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return $this->json(['error' => $message], $status);
    }
}
