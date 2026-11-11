<?php

declare(strict_types=1);

namespace Guild\Framework\Authentication;

use Guild\Framework\Authorization\Identity\IdentityReader;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * How this app signs people in and out.
 */
interface SignInMethod extends IdentityReader
{
    /** Let a signed-in request through, or start signing the visitor in. */
    public function requireSignIn(ServerRequestInterface $request, RequestHandlerInterface $next): ResponseInterface;

    /** Ask a guest to sign in, e.g. after AuthenticationRequiredException. */
    public function challenge(ServerRequestInterface $request): ResponseInterface;

    /** End the signed-in session and say where to go next. */
    public function signOut(ServerRequestInterface $request): ResponseInterface;
}
