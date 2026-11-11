<?php

declare(strict_types=1);

namespace Guild\Framework\Session;

use Override;

/**
 * The PHP session, started the first time the framework needs it.
 *
 * On the OIDC path guild/access has usually started the session already. On
 * the CAS path nothing has, because Apache authenticates before PHP runs, so
 * this starts one. The cookie parameters match the ones guild/access sets, so
 * the two never disagree about the cookie.
 *
 * @internal
 */
final readonly class NativeSessionStore implements SessionStore
{
    #[Override]
    public function get(string $key): mixed
    {
        $this->ensureStarted();

        return $_SESSION[$key] ?? null;
    }

    #[Override]
    public function put(string $key, mixed $value): void
    {
        $this->ensureStarted();

        $_SESSION[$key] = $value;
    }

    #[Override]
    public function forget(string $key): void
    {
        $this->ensureStarted();

        unset($_SESSION[$key]);
    }

    #[Override]
    public function regenerateId(): void
    {
        $this->ensureStarted();

        session_regenerate_id(true);
    }

    private function ensureStarted(): void
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
