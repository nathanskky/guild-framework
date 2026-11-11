<?php

declare(strict_types=1);

namespace Guild\Framework\Routing;

use Guild\Framework\Authorization\StatusPage;
use Guild\Framework\Exception\ConfigurationException;
use League\Route\Http\Exception\MethodNotAllowedException;
use League\Route\Http\Exception\NotFoundException;
use League\Route\Strategy\ApplicationStrategy;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * League's application strategy, except that a request no route answers gets
 * a status page instead of an uncaught exception: 404 for an address with no
 * page, 405 (with Allow) for a page asked with a method it doesn't take.
 *
 * Router-level middleware (a banner, security headers) still wraps the status
 * page as it wraps any page; see StatusPageResponder.
 *
 * @internal
 */
final class StatusPageStrategy extends ApplicationStrategy
{
    #[\Override]
    public function getNotFoundDecorator(NotFoundException $exception): MiddlewareInterface
    {
        return $this->responder('Page not found', 'There is no page at this address.', 404, []);
    }

    #[\Override]
    public function getMethodNotAllowedDecorator(MethodNotAllowedException $exception): MiddlewareInterface
    {
        $allow = $exception->getHeaders()['Allow'] ?? null;

        return $this->responder(
            'Method not allowed',
            'This page does not accept that kind of request.',
            405,
            is_string($allow) ? ['Allow' => $allow] : [],
        );
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function responder(string $title, string $message, int $status, array $headers): StatusPageResponder
    {
        $container = $this->getContainer();
        $page = $container?->get(StatusPage::class);

        if (! $container instanceof ContainerInterface || ! $page instanceof StatusPage) {
            throw new ConfigurationException('The routing strategy needs the container to provide a StatusPage.');
        }

        return new StatusPageResponder($container, $page, $title, $message, $status, $headers);
    }
}
