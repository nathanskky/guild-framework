<?php

declare(strict_types=1);

namespace Guild\Framework\Exception;

/**
 * Thrown by Gate::authorize() when a check is denied to a guest.
 *
 * A handler for AuthorizationException catches it too. addAuthorization()
 * answers it with the sign-in method's challenge under OIDC, and with a 403
 * under CAS, where Apache does the signing in.
 */
class AuthenticationRequiredException extends AuthorizationException
{
}
