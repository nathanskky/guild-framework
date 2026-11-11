<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authentication\Support;

use Guild\Framework\Authentication\SignInMethod;
use Guild\Framework\Authorization\Identity\Identity;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A sign-in method for tests: the identity is handed in, and challenging or
 * signing out only redirects.
 */
final class FakeSignIn implements SignInMethod
{
    public int $signOuts = 0;

    public function __construct(private ?Identity $identity = null)
    {
    }

    #[\Override]
    public function read(): ?Identity
    {
        return $this->identity;
    }

    #[\Override]
    public function requireSignIn(ServerRequestInterface $request, RequestHandlerInterface $next): ResponseInterface
    {
        if ($this->identity === null) {
            return $this->challenge($request);
        }

        return $next->handle($request);
    }

    #[\Override]
    public function challenge(ServerRequestInterface $request): ResponseInterface
    {
        return new RedirectResponse('/fake-sign-in?return=' . urlencode($request->getRequestTarget()));
    }

    #[\Override]
    public function signOut(ServerRequestInterface $request): ResponseInterface
    {
        $this->identity = null;
        $this->signOuts++;

        return new RedirectResponse('/signed-out');
    }
}
