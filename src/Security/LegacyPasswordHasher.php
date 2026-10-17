<?php

namespace App\Security;

use Symfony\Component\PasswordHasher\Hasher\MessageDigestPasswordHasher;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * Verifies passwords stored by the Symfony 2 forum (FOSUserBundle, sha512 x5000, base64, per-user salt).
 *
 * The old forum's custom authentication provider also accepted members whose password predates FOSUserBundle:
 * those were stored as the sha512 digest of sha1(sha1(password)). Both forms are accepted here; on a successful
 * login the hash is upgraded to the modern one (migrate_from), so this class only ever runs once per member.
 */
final class LegacyPasswordHasher implements PasswordHasherInterface
{
    private readonly MessageDigestPasswordHasher $digest;

    public function __construct()
    {
        $this->digest = new MessageDigestPasswordHasher('sha512', true, 5000);
    }

    public function hash(#[\SensitiveParameter] string $plainPassword, ?string $salt = null): string
    {
        return $this->digest->hash($plainPassword, $salt);
    }

    public function verify(string $hashedPassword, #[\SensitiveParameter] string $plainPassword, ?string $salt = null): bool
    {
        return $this->digest->verify($hashedPassword, $plainPassword, $salt)
            || $this->digest->verify($hashedPassword, sha1(sha1($plainPassword)), $salt);
    }

    public function needsRehash(string $hashedPassword): bool
    {
        return true;
    }
}
