<?php

declare(strict_types=1);

namespace Guild\Framework\Middleware;

use Guild\Framework\Authentication\SignInMethod;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Lets a signed-in request through and sends a guest to sign in, however
 * this application signs people in. Add it to a route with
 * `lazyMiddleware(RequireSignIn::class)`.
 */
final readonly class RequireSignIn implements MiddlewareInterface
{
    public function __construct(private SignInMethod $signIn)
    {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->signIn->requireSignIn($request, $handler);
    }
}
