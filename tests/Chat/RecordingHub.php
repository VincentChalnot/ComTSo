<?php

namespace App\Tests\Chat;

use Symfony\Component\Mercure\Exception\RuntimeException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\Update;

/**
 * Test double of the Mercure hub (decorates `mercure.hub.default` in the test environment, see config/packages/mercure.yaml):
 * keeps what would have been published instead of sending it to the real hub. Everything else (public URL, token factory,
 * cookie name) comes from the real configuration, so the subscriber cookie is the real thing.
 */
final class RecordingHub implements HubInterface
{
    /** @var list<Update> */
    public array $updates = [];

    /** Simulates a hub outage. */
    public bool $down = false;

    public function __construct(private readonly HubInterface $inner)
    {
    }

    public function publish(Update $update): string
    {
        if ($this->down) {
            throw new RuntimeException('Failed to send an update.');
        }

        $this->updates[] = $update;

        return 'urn:uuid:test-'.\count($this->updates);
    }

    public function getPublicUrl(): string
    {
        return $this->inner->getPublicUrl();
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return $this->inner->getFactory();
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return $this->inner->getProtocolVersion();
    }

    public function getCookieName(): string
    {
        return $this->inner->getCookieName();
    }
}
