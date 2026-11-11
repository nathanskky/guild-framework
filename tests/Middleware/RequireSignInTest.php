<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Middleware;

use Guild\Framework\Authorization\Identity\Identity;
use Guild\Framework\Middleware\RequireSignIn;
use Guild\Framework\Test\Authentication\Support\FakeSignIn;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(RequireSignIn::class)]
#[UsesClass(FakeSignIn::class)]
#[UsesClass(Identity::class)]
final class RequireSignInTest extends TestCase
{
    public function testAGuestGetsTheSignInMethodsChallenge(): void
    {
        $middleware = new RequireSignIn(new FakeSignIn());
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/x');

        $response = $middleware->process($request, $this->handlerAnswering(new EmptyResponse(200)));

        self::assertSame(302, $response->getStatusCode(), 'a guest is sent to sign in');
        self::assertSame('/fake-sign-in?return=%2Fx', $response->getHeaderLine('Location'), 'by the sign-in method, not the middleware');
    }

    public function testASignedInVisitorReachesTheRoute(): void
    {
        $middleware = new RequireSignIn(new FakeSignIn(new Identity('jdoe')));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/x');

        $response = $middleware->process($request, $this->handlerAnswering(new EmptyResponse(200)));

        self::assertSame(200, $response->getStatusCode(), 'the route answers a signed-in visitor');
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
