<?php

namespace App\Sentry;

use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * `before_send` of the Sentry SDK (config/packages/sentry.yaml): drops the exceptions that are the application working as
 * designed, because the visitor asked for something it refuses: 404, 403, 401, 405, 422, 429 (any HTTP exception below 500),
 * access denied, failed authentication and an invalid CSRF token (an AuthenticationException). Everything else, including
 * an HTTP exception of 500 and above, is reported.
 */
final class ExpectedErrorFilter
{
    public function __invoke(Event $event, ?EventHint $hint = null): ?Event
    {
        $exception = $hint?->exception;

        return null !== $exception && self::isExpected($exception) ? null : $event;
    }

    private static function isExpected(\Throwable $exception): bool
    {
        if ($exception instanceof HttpExceptionInterface) {
            return $exception->getStatusCode() < 500;
        }

        return $exception instanceof AccessDeniedException || $exception instanceof AuthenticationException;
    }
}
