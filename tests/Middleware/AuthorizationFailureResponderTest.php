<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Middleware;

use Guild\Framework\Authorization\Identity\Identity;
use Guild\Framework\Authorization\Identity\IdentityReader;
use Guild\Framework\Authorization\Identity\RemoteUserIdentityReader;
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
#[UsesClass(RemoteUserIdentityReader::class)]
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

    public function testASignedInVisitorAskedToSignInIsForbiddenInstead(): void
    {
        $signIn = new FakeSignIn(new Identity('jdoe'));

        $response = $this->respond($signIn, new AuthenticationRequiredException());

        self::assertSame(403, $response->getStatusCode(), 'signing in again cannot help someone already signed in');
        self::assertStringContainsString('<h1>Not authorized</h1>', (string) $response->getBody(), 'so they are refused, not challenged');
        self::assertSame('jdoe', $signIn->read()?->username, 'and they stay signed in');
    }

    public function testUnderCasASignedInVisitorAskedToSignInIsForbiddenInstead(): void
    {
        $response = $this->respond(null, new AuthenticationRequiredException(), new RemoteUserIdentityReader(
            (new ServerRequestFactory())->createServerRequest('GET', '/secret', ['REMOTE_USER' => 'jdoe']),
        ));

        self::assertSame(403, $response->getStatusCode(), 'CAS already signed jdoe in');
        self::assertStringContainsString('<h1>Not authorized</h1>', (string) $response->getBody(), 'so the page does not ask them to sign in');
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

    public function testOtherExceptionsPropagate(): void
    {
        $this->expectException(RuntimeException::class);

        $this->respond(new FakeSignIn(), new RuntimeException('boom'));
    }

    public function testAResponseFromTheHandlerIsReturnedUntouched(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/secret');
        $responder = new AuthorizationFailureResponder(new StatusPage(static fn () => null), new RemoteUserIdentityReader($request), null);

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

    public function testTheFrameworkRefusesASignedInVisitorRatherThanChallengingThem(): void
    {
        [$response] = $this->dispatch(
            'GET',
            '/secret',
            null,
            [],
            signIn: new FakeSignIn(new Identity('jdoe')),
            routes: static function (Router $router): void {
                $router->map('GET', '/secret', static fn () => throw new AuthenticationRequiredException());
            },
        );

        self::assertSame(403, $response->getStatusCode(), 'the application\'s responder knows who is signed in');
        self::assertStringContainsString('Not authorized', (string) $response->getBody(), 'and refuses jdoe instead of sending them to sign in');
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

    /**
     * Without $identities, the visitor is whoever $signIn says, or under CAS a guest.
     */
    private function respond(?FakeSignIn $signIn, Throwable $thrown, ?IdentityReader $identities = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/secret');
        $responder = new AuthorizationFailureResponder(
            new StatusPage(static fn () => null),
            $identities ?? $signIn ?? new RemoteUserIdentityReader($request),
            $signIn,
        );

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
