<?php

declare(strict_types=1);

namespace Guild\Framework\Middleware;

use Guild\Framework\Admin\AdminPage;
use Guild\Framework\Authorization\IdentitySource;
use Guild\Framework\Authorization\UserResolver;
use Guild\Framework\Exception\AuthorizationUnavailableException;
use Guild\Rivet\Html\Html;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Admits only members of the System Admin group, on current membership.
 *
 * This is the gate for every /framework route; the navigation menu's absence
 * is cosmetic and never what denies access. When membership cannot be
 * confirmed — Grouper unavailable, cached membership never being used for this
 * check — the answer is a 503, not a 403: nobody has been refused, the
 * question simply could not be asked.
 *
 * @internal
 */
final readonly class RequireSystemAdmin implements MiddlewareInterface
{
    public function __construct(
        private UserResolver $users,
        private AdminPage $page,
        private IdentitySource $identitySource,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $this->users->current();

        if ($user === null) {
            return $this->page->response('Sign in required', $this->paragraph(
                $this->identitySource === IdentitySource::Cas
                    ? 'You are not signed in. These pages must be protected by CAS in the web server configuration.'
                    : 'You are not signed in.'
            ), status: 403);
        }

        try {
            $isSystemAdmin = $user->isSystemAdmin();
        } catch (AuthorizationUnavailableException) {
            return $this->page->response('Temporarily unavailable', $this->paragraph(
                'Your group membership could not be confirmed because Grouper is unavailable. Try again shortly.'
            ), status: 503);
        }

        if (! $isSystemAdmin) {
            return $this->page->response('Not authorized', $this->paragraph(
                'These pages are for members of the application\'s System Admin group.'
            ), status: 403);
        }

        return $handler->handle($request);
    }

    private function paragraph(string $text): string
    {
        return Html::el('p')->text($text)->render();
    }
}
