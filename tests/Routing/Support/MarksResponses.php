<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Routing\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Router middleware that marks every response it wraps, so a test can see
 * which responses passed through the router's middleware.
 */
final readonly class MarksResponses implements MiddlewareInterface
{
    public const string HEADER = 'X-Router-Middleware';

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withHeader(self::HEADER, 'yes');
    }
}
