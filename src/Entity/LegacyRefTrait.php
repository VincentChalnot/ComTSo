<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Provenance of a row carried over from an older platform (e.g. "phpbb3:post:1234"); kept for
 * reference only, nothing imports from it anymore.
 */
trait LegacyRefTrait
{
    #[ORM\Column(name: 'legacy_ref', type: Types::STRING, length: 64, nullable: true, unique: true)]
    private ?string $legacyRef = null;

    public function getLegacyRef(): ?string
    {
        return $this->legacyRef;
    }

    public function setLegacyRef(?string $legacyRef): static
    {
        $this->legacyRef = $legacyRef;

        return $this;
    }
}
