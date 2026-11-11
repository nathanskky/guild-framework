<?php

declare(strict_types=1);

namespace Guild\Framework\Controller\Authentication;

use Guild\Framework\Admin\Csrf;
use Guild\Framework\Authentication\SignInMethod;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /framework/sign-out: ends the visitor's session through the
 * application's sign-in method, which also decides where they land, then
 * rotates the CSRF token so a form issued while signed in is worthless after.
 * Mounted by addAuthentication(), behind VerifyCsrfToken.
 *
 * @internal
 */
final readonly class SignOutController
{
    public function __construct(private SignInMethod $signIn, private Csrf $csrf)
    {
    }

    public function signOut(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->signIn->signOut($request);
        $this->csrf->rotate();

        return $response;
    }
}
