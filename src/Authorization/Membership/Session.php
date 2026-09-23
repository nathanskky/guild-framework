<?php

declare(strict_types=1);

namespace Guild\Framework\Authorization\Membership;

/**
 * Starts the PHP session when the framework needs one and nothing else has
 * started it.
 *
 * On the OIDC path guild/access has usually started the session already. On
 * the CAS path nothing has, because Apache authenticates before PHP runs, so
 * the membership cache would have nowhere to live. The cookie parameters match
 * the ones guild/access sets, so the two never disagree about the cookie.
 */
final readonly class Session
{
    public function ensureStarted(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (! headers_sent()) {
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => true,
            ]);
        }

        session_start();
    }
}
