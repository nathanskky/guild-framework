<?php

declare(strict_types=1);

namespace Guild\Framework\Admin;

use Guild\Framework\Session\SessionStore;
use Guild\Rivet\Html\Html;

/**
 * A per-session token proving a form was issued by this application.
 *
 * The session cookie is already SameSite=Lax, which stops most cross-site
 * form posts; the token is the second layer, for pages that change who may do
 * what.
 *
 * API for extensions' own forms: render field() in the form and put
 * VerifyCsrfToken on the route. Call rotate() when the signed-in user changes.
 */
final readonly class Csrf
{
    public const string FIELD = '_csrf';

    private const string KEY = 'guild_framework_csrf';

    public function __construct(private SessionStore $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::KEY);

        if (! is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->put(self::KEY, $token);
        }

        return $token;
    }

    /**
     * Forget the token, so the next form gets a new one and every form issued
     * before no longer validates.
     */
    public function rotate(): void
    {
        $this->session->forget(self::KEY);
    }

    public function field(): Html
    {
        return Html::el('input')->attr('type', 'hidden')->attr('name', self::FIELD)->attr('value', $this->token());
    }

    public function isValid(mixed $submitted): bool
    {
        return is_string($submitted) && $submitted !== '' && hash_equals($this->token(), $submitted);
    }
}
