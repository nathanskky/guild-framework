<?php

declare(strict_types=1);

namespace Guild\Framework\Middleware;

use Guild\Framework\Admin\Csrf;
use Guild\Framework\Authorization\StatusPage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Rejects any state-changing request whose form did not carry this session's
 * CSRF token (Csrf::field()). API: extensions put it on their own form routes.
 * The refusal is a status page, so it carries the application's layout with
 * Rivet and is plain HTML without it, wherever the form was.
 */
final readonly class VerifyCsrfToken implements MiddlewareInterface
{
    private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private Csrf $csrf, private StatusPage $page)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        $body = $request->getParsedBody();
        $submitted = is_array($body) ? ($body[Csrf::FIELD] ?? null) : null;

        if (! $this->csrf->isValid($submitted)) {
            return $this->page->response(
                'Form expired',
                'This form could not be accepted. Go back, reload the page and try again.',
                403,
            );
        }

        return $handler->handle($request);
    }
}
