<?php

declare(strict_types=1);

namespace Guild\Framework\Authorization\Identity;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads the username Apache mod_auth_cas leaves in REMOTE_USER.
 *
 * Only the server parameter is read, never a request header, so a client
 * cannot supply its own. An empty value means either a path Apache leaves
 * unprotected or CAS not being applied at all; the two are indistinguishable
 * here, so both read as a guest.
 */
final readonly class RemoteUserIdentityReader implements IdentityReader
{
    public function __construct(private ServerRequestInterface $request)
    {
    }

    public function read(): ?Identity
    {
        $username = $this->request->getServerParams()['REMOTE_USER'] ?? null;

        if (! is_string($username) || trim($username) === '') {
            return null;
        }

        return new Identity(trim($username));
    }
}
