<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Middleware;

use Guild\Framework\Authentication\SignInMethod;
use Guild\Framework\Authorization\Identity\Identity;
use Guild\Framework\Middleware\RequireSignIn;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(RequireSignIn::class)]
final class RequireSignInTest extends TestCase
{
    /**
     * What a guest or a signed-in visitor gets is the sign-in method's to decide; FakeSignInTest and
     * IuLoginSignInTest cover that. This shows the middleware passes the request and the route on unchanged.
     */
    public function testTheSignInMethodDecides(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/x');
        $route = $this->handlerAnswering(new EmptyResponse(200));
        $signIn = new class () implements SignInMethod {
            public ?ServerRequestInterface $request = null;

            public ?RequestHandlerInterface $next = null;

            #[\Override]
            public function read(): ?Identity
            {
                return null;
            }

            #[\Override]
            public function requireSignIn(ServerRequestInterface $request, RequestHandlerInterface $next): ResponseInterface
            {
                $this->request = $request;
                $this->next = $next;

                return new EmptyResponse(418);
            }

            #[\Override]
            public function challenge(ServerRequestInterface $request): ResponseInterface
            {
                return new EmptyResponse(500);
            }

            #[\Override]
            public function signOut(ServerRequestInterface $request): ResponseInterface
            {
                return new EmptyResponse(500);
            }
        };

        $response = new RequireSignIn($signIn)->process($request, $route);

        self::assertSame(418, $response->getStatusCode(), 'the sign-in method\'s answer is the answer');
        self::assertSame($request, $signIn->request, 'it was given the request');
        self::assertSame($route, $signIn->next, 'and the route, to let a signed-in visitor through');
    }

    private function handlerAnswering(ResponseInterface $response): RequestHandlerInterface
    {
        return new class ($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response)
            {
            }

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }
}
