<?php

declare(strict_types=1);

namespace Guild\Framework\Authorization;

/**
 * Where the authenticated user's identity comes from. Always configured
 * explicitly, never detected: the framework does not sniff for REMOTE_USER.
 */
enum IdentitySource
{
    /**
     * IU Login over OIDC, via guild/access. Requires addAuthentication().
     */
    case Oidc;

    /**
     * Apache mod_auth_cas, which authenticates before PHP runs and leaves the
     * username in REMOTE_USER. Nothing richer than the username is available.
     */
    case Cas;
}
