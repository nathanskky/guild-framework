<?php

declare(strict_types=1);

namespace Guild\Framework\Controller\Authentication;

use Guild\Framework\Authentication\SignInMethod;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /framework/sign-out: ends the visitor's session through the
 * application's sign-in method, which also decides where they land. Mounted by
 * addAuthentication(), behind VerifyCsrfToken.
 *
 * @internal
 */
final readonly class SignOutController
{
    public function __construct(private SignInMethod $signIn)
    {
    }

    public function signOut(ServerRequestInterface $request): ResponseInterface
    {
        return $this->signIn->signOut($request);
    }
}
