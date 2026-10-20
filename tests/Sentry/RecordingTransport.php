<?php

namespace App\Tests\Sentry;

use Sentry\Event;
use Sentry\Transport\Result as TransportResult;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

/** Stands in for the Sentry HTTP transport in the test environment: records the events instead of sending them. */
final class RecordingTransport implements TransportInterface
{
    /** @var list<Event> */
    private array $events = [];

    public function send(Event $event): TransportResult
    {
        $this->events[] = $event;

        return new TransportResult(ResultStatus::success(), $event);
    }

    public function close(?int $timeout = null): TransportResult
    {
        return new TransportResult(ResultStatus::success());
    }

    /** @return list<Event> */
    public function events(): array
    {
        return $this->events;
    }
}
