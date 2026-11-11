<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authentication;

use Guild\Access\Authentication\OIDC\OidcAuthenticationService;
use Guild\Access\Authentication\OIDC\OidcConfiguration;
use Guild\Framework\Authentication\IuLoginSignIn;
use Guild\Framework\Authorization\Identity\Identity;
use Guild\Framework\Exception\ConfigurationException;
use Guild\Framework\Session\NativeSessionStore;
use Jumbojett\OpenIDConnectClientException as OidcClientException;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequest;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * guild/access keeps the login in the PHP session, so each test runs in its
 * own process with a real session. The redirect of a guest to IU Login calls
 * the provider's discovery endpoint, so it is not exercised here.
 */
#[CoversClass(IuLoginSignIn::class)]
#[UsesClass(Identity::class)]
#[UsesClass(NativeSessionStore::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class IuLoginSignInTest extends TestCase
{
    /** A local port nothing listens on: discovery is refused at once, without leaving the machine. */
    private const string UNREACHABLE = 'https://127.0.0.1:1';

    private TestHandler $logs;

    protected function setUp(): void
    {
        $this->logs = new TestHandler();
    }

    public function testAnAuthenticatedLoginReadsAsTheUsernameAndClaims(): void
    {
        $this->login(['username' => 'jdoe', 'email' => 'jdoe@iu.edu']);

        $identity = $this->signIn()->read();

        self::assertNotNull($identity, 'an unexpired login is a user');
        self::assertSame('jdoe', $identity->username, 'the username claim is the identity');
        self::assertSame('jdoe@iu.edu', $identity->attributes['email'], 'other claims are carried as attributes');
    }

    public function testNoLoginIsAGuest(): void
    {
        session_start();

        self::assertNull($this->signIn()->read(), 'nothing in the session means a guest');
    }

    public function testAnExpiredLoginIsAGuestEvenThoughUserinfoRemains(): void
    {
        $this->login(['username' => 'jdoe'], expiresAt: time() - 1);

        self::assertNull($this->signIn()->read(), 'an expired login must not read as a user');
    }

    public function testAMissingUsernameClaimThrows(): void
    {
        $this->login(['email' => 'jdoe@iu.edu']);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("'username' claim");

        $this->signIn()->read();
    }

    public function testASignedInRequestReachesTheHandler(): void
    {
        $this->login(['username' => 'jdoe']);

        $response = $this->signIn()->requireSignIn(new ServerRequest(), $this->handler(new TextResponse('ok', 200)));

        self::assertSame(200, $response->getStatusCode(), 'the handler answers a signed-in request');
        self::assertSame('ok', (string) $response->getBody(), 'its response is returned untouched');
    }

    public function testAProviderErrorIsA400WithAGenericBody(): void
    {
        session_start();
        $request = (new ServerRequest())->withQueryParams([
            'error' => 'access_denied',
            'error_description' => '<script>',
        ]);

        $response = $this->signIn()->requireSignIn($request, $this->handler(new TextResponse('unreachable', 200)));

        self::assertSame(400, $response->getStatusCode(), 'a provider error is a bad request');
        self::assertSame('OIDC login failed.', (string) $response->getBody(), 'the body is generic');
        self::assertStringNotContainsString('<script>', (string) $response->getBody(), 'the description is not reflected');
    }

    public function testAnUnreachableIdentityProviderIsA401AndAWarning(): void
    {
        session_start();

        $response = $this->signIn(providerUrl: self::UNREACHABLE)
            ->requireSignIn(new ServerRequest(), $this->handler(new TextResponse('unreachable', 200)));

        self::assertSame(401, $response->getStatusCode(), 'a guest who cannot be sent to IU Login is not let through');
        self::assertTrue($this->logs->hasWarningThatContains('OIDC authentication failed'), 'and the failure is logged for the operator');
    }

    public function testAnExceptionFromTheHandlerIsNotTreatedAsASignInFailure(): void
    {
        $this->login(['username' => 'jdoe']);
        $handler = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new OidcClientException('downstream');
            }
        };

        try {
            $this->signIn()->requireSignIn(new ServerRequest(), $handler);
            self::fail('the handler exception should propagate');
        } catch (OidcClientException $exception) {
            self::assertSame('downstream', $exception->getMessage(), 'the application sees its own exception');
        }

        self::assertFalse($this->logs->hasWarningRecords(), 'no sign-in warning is logged for it');
        self::assertFalse($this->logs->hasErrorRecords(), 'no sign-in error is logged for it');
    }

    public function testChallengingAnAlreadySignedInRequestIsA403(): void
    {
        $this->login(['username' => 'jdoe']);

        self::assertSame(
            403,
            $this->signIn()->challenge(new ServerRequest())->getStatusCode(),
            'there is nothing to sign in to, so the denial stands',
        );
    }

    public function testSigningOutClearsTheLoginAndRedirectsHome(): void
    {
        $this->login(['username' => 'jdoe']);
        $before = session_id();

        $response = $this->signIn()->signOut(new ServerRequest());

        self::assertSame(302, $response->getStatusCode(), 'signing out redirects');
        self::assertSame('/', $response->getHeaderLine('Location'), 'the visitor goes to the default return URL');
        self::assertNull($this->signIn()->read(), 'the login is gone');
        self::assertNotSame($before, session_id(), 'the session ID changes');
    }

    /**
     * @param  array<string, string>  $claims
     */
    private function login(array $claims, ?int $expiresAt = null): void
    {
        session_start();
        $_SESSION['guild_oidc_user_info'] = (object) $claims;
        $_SESSION['guild_oidc_authenticated_until'] = $expiresAt ?? time() + 3600;
    }

    private function signIn(string $providerUrl = 'https://idp.login.iu.edu'): IuLoginSignIn
    {
        $logger = new Logger('test', [$this->logs]);

        return new IuLoginSignIn(
            new OidcAuthenticationService(
                new OidcConfiguration($providerUrl, 'id', 'secret', 'https://app.iu.edu/cb'),
                $logger,
            ),
            new NativeSessionStore(),
            $logger,
        );
    }

    private function handler(ResponseInterface $response): RequestHandlerInterface
    {
        return new readonly class ($response) implements RequestHandlerInterface {
            public function __construct(private ResponseInterface $response)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }
}
