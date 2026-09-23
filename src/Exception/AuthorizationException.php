<?php

declare(strict_types=1);

namespace Guild\Framework\Exception;

use RuntimeException;

/**
 * Thrown by Gate::authorize() when a check is denied. An application
 * typically renders it as a 403.
 */
class AuthorizationException extends RuntimeException
{
}
