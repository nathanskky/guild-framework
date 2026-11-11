<?php

declare(strict_types=1);

namespace Guild\Framework\Middleware;

use Guild\Framework\Authentication\SignInMethod;
use Guild\Framework\Authorization\Identity\IdentityReader;
use Guild\Framework\Authorization\StatusPage;
use Guild\Framework\Exception\AuthenticationRequiredException;
use Guild\Framework\Exception\AuthorizationException;
use Guild\Framework\Exception\AuthorizationUnavailableException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Turns the authorization exceptions a route throws into responses: a guest is
 * sent to sign in, a denied user gets a 403, and a membership that could not
 * be confirmed gets a 503. Any other exception propagates.
 *
 * Whether to challenge is decided here, from who is signed in, not by the
 * exception alone: a visitor who is already signed in gets the 403 even when
 * AuthenticationRequiredException was thrown, because signing in again cannot
 * help them. SignInMethod::challenge() is only ever asked to sign a guest in.
 *
 * Without a sign-in method (CAS, where Apache authenticates requests) a guest
 * is told to sign in on a 403, since there is nowhere to send them.
 *
 * @internal
 */
final readonly class AuthorizationFailureResponder implements MiddlewareInterface
{
    public function __construct(
        private StatusPage $page,
        private IdentityReader $identities,
        private ?SignInMethod $signIn,
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (AuthenticationRequiredException) {
            if ($this->identities->read() !== null) {
                return $this->forbidden();
            }

            if ($this->signIn !== null) {
                return $this->signIn->challenge($request);
            }

            return $this->page->response(
                'Sign in required',
                'You are not signed in. This page must be protected by CAS in the web server configuration.',
                403,
            );
        } catch (AuthorizationException) {
            return $this->forbidden();
        } catch (AuthorizationUnavailableException) {
            return $this->page->response(
                'Temporarily unavailable',
                'Your group membership could not be confirmed because Grouper is unavailable. Try again shortly.',
                503,
            );
        }
    }

    private function forbidden(): ResponseInterface
    {
        return $this->page->response('Not authorized', 'You do not have permission to view this page.', 403);
    }
}
