<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Identifies a row imported from an older platform (e.g. "phpbb3:post:1234"), so that
 * importing the same source twice never duplicates anything.
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
