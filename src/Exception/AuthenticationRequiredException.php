<?php

declare(strict_types=1);

namespace Guild\Framework\Exception;

/**
 * Thrown by Gate::authorize() when a check is denied to a guest.
 *
 * A handler for AuthorizationException catches it too; an application that
 * would rather send a guest to log in than show a 403 catches this first.
 */
class AuthenticationRequiredException extends AuthorizationException
{
}
