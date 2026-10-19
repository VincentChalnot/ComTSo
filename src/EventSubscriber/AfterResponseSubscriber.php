<?php

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * Runs work once the response has been sent: {@see self::defer()} stores a callable on the request, which this listener
 * calls on `kernel.terminate`. Used so that sending an e-mail does not make a request measurably slower than one that
 * sends none (no way to tell, by timing, which accounts exist). Stored on the request, never on the dispatcher: the
 * kernel survives across requests in FrankenPHP worker mode.
 *
 * Deferred tasks must NOT render Twig templates: once the response is closed Twig cannot start an output buffer any more
 * (`ob_start(): Failed to create buffer`) and renders nothing (see App\Mail\TemplatedMailFactory). Prepare in the request, deliver here.
 */
#[AsEventListener(event: TerminateEvent::class)]
final class AfterResponseSubscriber
{
    private const ATTRIBUTE = '_after_response';

    public static function defer(Request $request, callable $task): void
    {
        $request->attributes->set(self::ATTRIBUTE, [...$request->attributes->get(self::ATTRIBUTE, []), $task]);
    }

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(TerminateEvent $event): void
    {
        foreach ($event->getRequest()->attributes->get(self::ATTRIBUTE, []) as $task) {
            try {
                $task();
            } catch (\Throwable $e) {
                // The visitor already has their response: all that is left is to report the failure.
                $this->logger->error('Deferred task failed: '.$e->getMessage(), ['exception' => $e]);
            }
        }
    }
}
