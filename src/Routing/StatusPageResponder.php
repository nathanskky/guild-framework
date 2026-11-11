<?php

declare(strict_types=1);

namespace Guild\Framework\Routing;

use Guild\Framework\Authorization\StatusPage;
use Guild\Framework\Exception\ConfigurationException;
use League\Route\Middleware\MiddlewareAwareInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Answers a request no route matched with a status page, run through the
 * router-level middleware after it.
 *
 * League puts this decorator in front of the router's middleware and, on this
 * path, leaves middleware registered by class name unresolved. So it takes the
 * rest of the dispatcher's stack, resolves each name from the container as
 * League does for a matched route, and runs it with the status page at the end.
 *
 * @internal
 */
final class StatusPageResponder implements MiddlewareInterface, RequestHandlerInterface
{
    /** @var list<mixed> */
    private array $remaining = [];

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly StatusPage $page,
        private readonly string $title,
        private readonly string $message,
        private readonly int $status,
        private readonly array $headers,
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->remaining = $handler instanceof MiddlewareAwareInterface
            ? array_values([...$handler->getMiddlewareStack()])
            : [];

        return $this->handle($request);
    }

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->remaining === []) {
            return $this->respond();
        }

        return $this->resolve(array_shift($this->remaining))->process($request, $this);
    }

    private function resolve(mixed $middleware): MiddlewareInterface
    {
        if (is_string($middleware) && $this->container->has($middleware)) {
            $middleware = $this->container->get($middleware);
        }

        if (! $middleware instanceof MiddlewareInterface) {
            throw new ConfigurationException(sprintf(
                'Router middleware %s could not be resolved to a PSR-15 middleware. Bind it in the container, or call enableAutoWiring().',
                is_string($middleware) ? $middleware : get_debug_type($middleware),
            ));
        }

        return $middleware;
    }

    private function respond(): ResponseInterface
    {
        $response = $this->page->response($this->title, $this->message, $this->status);

        foreach ($this->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
