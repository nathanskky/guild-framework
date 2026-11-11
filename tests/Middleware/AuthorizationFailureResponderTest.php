<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Middleware;

use Guild\Framework\Authorization\Identity\Identity;
use Guild\Framework\Authorization\StatusPage;
use Guild\Framework\Exception\AuthenticationRequiredException;
use Guild\Framework\Exception\AuthorizationException;
use Guild\Framework\Exception\AuthorizationUnavailableException;
use Guild\Framework\Middleware\AuthorizationFailureResponder;
use Guild\Framework\Router;
use Guild\Framework\Test\Admin\DispatchesAdministration;
use Guild\Framework\Test\Authentication\Support\FakeSignIn;
use Guild\Grouper\Testing\FakeGrouper;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

#[CoversClass(AuthorizationFailureResponder::class)]
#[UsesClass(StatusPage::class)]
#[UsesClass(FakeSignIn::class)]
#[UsesClass(Identity::class)]
final class AuthorizationFailureResponderTest extends TestCase
{
    use DispatchesAdministration;

    public function testAGuestDeniedIsChallenged(): void
    {
        $response = $this->respond(new FakeSignIn(), new AuthenticationRequiredException());

        self::assertSame(302, $response->getStatusCode(), 'a guest is sent to sign in');
        self::assertSame('/fake-sign-in?return=%2Fsecret', $response->getHeaderLine('Location'), 'by the sign-in method');
    }

    public function testAGuestDeniedUnderCasIsAskedToSignIn(): void
    {
        $response = $this->respond(null, new AuthenticationRequiredException());

        self::assertSame(403, $response->getStatusCode(), 'there is no sign-in method to send a guest to');
        self::assertStringContainsString('Sign in required', (string) $response->getBody(), 'the page says why');
        self::assertStringContainsString('protected by CAS', (string) $response->getBody(), 'and where to fix it');
    }

    public function testADeniedUserIsForbidden(): void
    {
        $response = $this->respond(new FakeSignIn(new Identity('jdoe')), new AuthorizationException());

        self::assertSame(403, $response->getStatusCode(), 'a signed-in user is refused');
        self::assertStringContainsString('<h1>Not authorized</h1>', (string) $response->getBody(), 'the page says so');
        self::assertStringContainsString('<html lang="en">', (string) $response->getBody(), 'as a plain HTML5 document without Rivet');
    }

    public function testAnUnansweredMembershipIsUnavailable(): void
    {
        $response = $this->respond(new FakeSignIn(new Identity('jdoe')), new AuthorizationUnavailableException());

        self::assertSame(503, $response->getStatusCode(), 'nobody was refused; the question could not be asked');
        self::assertStringContainsString('<h1>Temporarily unavailable</h1>', (string) $response->getBody(), 'the page says so');
    }

    public function testTheMessageIsEscaped(): void
    {
        $page = new StatusPage(static fn () => null);

        $response = $page->response('<b>Title</b>', '<script>x</script>', 403);

        self::assertStringNotContainsString('<script>', (string) $response->getBody(), 'text is escaped, not interpreted');
        self::assertStringContainsString('&lt;b&gt;Title&lt;/b&gt;', (string) $response->getBody(), 'in the title and heading');
    }

    public function testOtherExceptionsPropagate(): void
    {
        $this->expectException(RuntimeException::class);

        $this->respond(new FakeSignIn(), new RuntimeException('boom'));
    }

    public function testAResponseFromTheHandlerIsReturnedUntouched(): void
    {
        $responder = new AuthorizationFailureResponder(new StatusPage(static fn () => null), null);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/secret');

        $response = $responder->process($request, new class () implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new \Laminas\Diactoros\Response\EmptyResponse(204);
            }
        });

        self::assertSame(204, $response->getStatusCode(), 'nothing to answer when nothing was thrown');
    }

    public function testWithRivetThePageUsesTheApplicationLayout(): void
    {
        [$response] = $this->dispatch(
            'GET',
            '/secret',
            'jdoe',
            [FakeGrouper::membership([])],
            rivet: true,
            routes: static function (Router $router): void {
                $router->map('GET', '/secret', static fn () => throw new AuthorizationException());
            },
        );

        self::assertSame(403, $response->getStatusCode(), 'the denial is a 403');
        self::assertStringContainsString('Course Catalog', (string) $response->getBody(), 'in the application\'s layout');
        self::assertStringContainsString('Not authorized', (string) $response->getBody(), 'with the reason');
    }

    public function testTheFrameworkAppliesItToApplicationRoutesUnderOidc(): void
    {
        [$response] = $this->dispatch(
            'GET',
            '/secret',
            null,
            [],
            signIn: new FakeSignIn(),
            routes: static function (Router $router): void {
                $router->map('GET', '/secret', static fn () => throw new AuthenticationRequiredException());
            },
        );

        self::assertSame(302, $response->getStatusCode(), 'a guest is challenged by the application\'s sign-in method');
        self::assertSame('/fake-sign-in?return=%2Fsecret', $response->getHeaderLine('Location'), 'and returns to the page');
    }

    public function testTheFrameworkAppliesItToApplicationRoutesUnderCas(): void
    {
        [$response] = $this->dispatch(
            'GET',
            '/secret',
            null,
            [],
            routes: static function (Router $router): void {
                $router->map('GET', '/secret', static fn () => throw new AuthenticationRequiredException());
            },
        );

        self::assertSame(403, $response->getStatusCode(), 'no sign-in method is bound under CAS');
        self::assertStringContainsString('Sign in required', (string) $response->getBody(), 'so the guest is told to sign in');
    }

    private function respond(?FakeSignIn $signIn, Throwable $thrown): ResponseInterface
    {
        $responder = new AuthorizationFailureResponder(new StatusPage(static fn () => null), $signIn);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/secret');

        return $responder->process($request, new class ($thrown) implements RequestHandlerInterface {
            public function __construct(private readonly Throwable $thrown)
            {
            }

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->thrown;
            }
        });
    }
}
