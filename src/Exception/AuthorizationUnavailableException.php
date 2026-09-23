<?php

declare(strict_types=1);

namespace Guild\Framework\Exception;

use RuntimeException;

/**
 * Thrown when an authorization question cannot be answered because the user's
 * group membership could not be determined: Grouper is unreachable and no
 * cached membership is recent enough to use.
 *
 * Deliberately not an AuthorizationException. "You may not" and "we could not
 * ask" must not render the same way; an application typically renders this as
 * a 503 rather than a 403.
 */
class AuthorizationUnavailableException extends RuntimeException
{
}
