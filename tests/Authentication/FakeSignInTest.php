<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authentication;

use Guild\Framework\Authentication\SignInMethod;
use Guild\Framework\Authorization\Identity\Identity;
use Guild\Framework\Test\Authentication\Support\FakeSignIn;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(FakeSignIn::class)]
#[UsesClass(Identity::class)]
final class FakeSignInTest extends TestCase
{
    public function testAGuestIsChallengedWithTheReturnPath(): void
    {
        $signIn = new FakeSignIn();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/documents?page=2&sort=name');

        $response = $signIn->requireSignIn($request, $this->handlerAnswering(new Response\EmptyResponse(204)));

        self::assertSame(302, $response->getStatusCode(), 'a guest is sent to sign in, not to the page');
        self::assertSame(
            '/fake-sign-in?return=' . urlencode('/documents?page=2&sort=name'),
            $response->getHeaderLine('Location'),
            'the challenge carries the url-encoded request target so the visitor comes back',
        );
        self::assertNull($signIn->read(), 'challenging a guest does not sign anyone in');
    }

    public function testASignedInVisitorReachesTheHandler(): void
    {
        $signIn = new FakeSignIn(new Identity('jdoe'));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/documents');

        $response = $signIn->requireSignIn($request, $this->handlerAnswering(new Response\EmptyResponse(204)));

        self::assertSame(204, $response->getStatusCode(), 'the handler decides the response for a signed-in visitor');
        self::assertSame('jdoe', $signIn->read()?->username, 'the identity is what the double was given');
    }

    public function testSigningOutMakesTheVisitorAGuest(): void
    {
        $signIn = new FakeSignIn(new Identity('jdoe'));
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/framework/sign-out');

        $response = $signIn->signOut($request);

        self::assertSame(302, $response->getStatusCode(), 'signing out redirects');
        self::assertSame('/signed-out', $response->getHeaderLine('Location'), 'the visitor lands on the signed-out page');
        self::assertNull($signIn->read(), 'nobody is signed in afterwards');
        self::assertSame(1, $signIn->signOuts, 'the sign-out is counted');
        self::assertInstanceOf(SignInMethod::class, $signIn, 'the double is a sign-in method');
    }

    private function handlerAnswering(ResponseInterface $response): RequestHandlerInterface
    {
        return new readonly class ($response) implements RequestHandlerInterface {
            public function __construct(private ResponseInterface $response)
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
