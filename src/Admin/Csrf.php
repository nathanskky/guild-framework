<?php

declare(strict_types=1);

namespace Guild\Framework\Admin;

use Guild\Framework\Authorization\Membership\Session;
use Guild\Rivet\Html\Html;

/**
 * A per-session token proving a form was issued by this application.
 *
 * The session cookie is already SameSite=Lax, which stops most cross-site
 * form posts; the token is the second layer, for pages that change who may do
 * what.
 *
 * @internal
 */
final readonly class Csrf
{
    public const string FIELD = '_csrf';

    private const string KEY = 'guild_framework_csrf';

    public function __construct(private Session $session)
    {
    }

    public function token(): string
    {
        $this->session->ensureStarted();

        $token = $_SESSION[self::KEY] ?? null;

        if (! is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::KEY] = $token;
        }

        return $token;
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
