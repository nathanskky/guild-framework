<?php

declare(strict_types=1);

namespace Guild\Framework\Routing;

use Guild\Framework\Authorization\StatusPage;
use Guild\Framework\Exception\ConfigurationException;
use League\Route\Http\Exception\MethodNotAllowedException;
use League\Route\Http\Exception\NotFoundException;
use League\Route\Middleware\MiddlewareAwareInterface;
use League\Route\Strategy\ApplicationStrategy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * League's application strategy, except that a request no route answers gets
 * a status page instead of an uncaught exception: 404 for an address with no
 * page, 405 (with Allow) for a page asked with a method it doesn't take.
 *
 * League puts these decorators in front of the router's middleware. So that
 * router-level middleware (a banner, security headers) still wraps the status
 * page as it wraps any page, the decorator adds the page to the end of the
 * chain and lets the request run through it.
 *
 * @internal
 */
final class StatusPageStrategy extends ApplicationStrategy
{
    #[\Override]
    public function getNotFoundDecorator(NotFoundException $exception): MiddlewareInterface
    {
        return $this->respondWith('Page not found', 'There is no page at this address.', 404, []);
    }

    #[\Override]
    public function getMethodNotAllowedDecorator(MethodNotAllowedException $exception): MiddlewareInterface
    {
        $allow = $exception->getHeaders()['Allow'] ?? null;

        return $this->respondWith(
            'Method not allowed',
            'This page does not accept that kind of request.',
            405,
            is_string($allow) ? ['Allow' => $allow] : [],
        );
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function respondWith(string $title, string $message, int $status, array $headers): MiddlewareInterface
    {
        $page = $this->getContainer()?->get(StatusPage::class);

        if (! $page instanceof StatusPage) {
            throw new ConfigurationException('The routing strategy needs the container to provide a StatusPage.');
        }

        return new readonly class ($page, $title, $message, $status, $headers) implements MiddlewareInterface {
            /**
             * @param  array<string, string>  $headers
             */
            public function __construct(
                private StatusPage $page,
                private string $title,
                private string $message,
                private int $status,
                private array $headers,
            ) {
            }

            #[\Override]
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                if (! $handler instanceof MiddlewareAwareInterface) {
                    return $this->respond();
                }

                $handler->middleware(new readonly class ($this->respond(...)) implements MiddlewareInterface {
                    /**
                     * @param  \Closure(): ResponseInterface  $respond
                     */
                    public function __construct(private \Closure $respond)
                    {
                    }

                    #[\Override]
                    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                    {
                        return ($this->respond)();
                    }
                });

                return $handler->handle($request);
            }

            private function respond(): ResponseInterface
            {
                $response = $this->page->response($this->title, $this->message, $this->status);

                foreach ($this->headers as $name => $value) {
                    $response = $response->withHeader($name, $value);
                }

                return $response;
            }
        };
    }
}
