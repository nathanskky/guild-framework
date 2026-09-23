<?php

declare(strict_types=1);

namespace Guild\Framework\Middleware;

use Guild\Framework\Admin\AdminPage;
use Guild\Framework\Admin\Csrf;
use Guild\Rivet\Html\Html;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Rejects any state-changing request whose form did not carry this session's
 * CSRF token.
 *
 * @internal
 */
final readonly class VerifyCsrfToken implements MiddlewareInterface
{
    private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private Csrf $csrf, private AdminPage $page)
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
            return $this->page->response('Form expired', Html::el('p')->text(
                'This form could not be accepted. Go back, reload the page and try again.'
            )->render(), status: 403);
        }

        return $handler->handle($request);
    }
}
