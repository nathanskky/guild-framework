<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Extension;

use Guild\Framework\Admin\AdminPage;
use Guild\Framework\Admin\Csrf;
use Guild\Framework\Application;
use Guild\Framework\ApplicationBuilder;
use Guild\Framework\Authentication\SignInMethod;
use Guild\Framework\Authorization\Identity\Identity;
use Guild\Framework\Authorization\IdentitySource;
use Guild\Framework\Controller\Authentication\SignOutController;
use Guild\Framework\Exception\ConfigurationException;
use Guild\Framework\Extension\ClaimedSlots;
use Guild\Framework\Extension\SlotClaim;
use Guild\Framework\Middleware\RequireSignIn;
use Guild\Framework\Middleware\VerifyCsrfToken;
use Guild\Framework\Router;
use Guild\Framework\ServiceProvider\AuthenticationServiceProvider;
use Guild\Framework\ServiceProvider\FrameworkPageServiceProvider;
use Guild\Framework\Session\SessionStore;
use Guild\Framework\Test\Authentication\Support\FakeSignIn;
use Guild\Framework\Test\Authorization\Support\TestPermission;
use Guild\Framework\Test\Extension\Support\ClaimingExtension;
use Guild\Framework\Testing\ArraySessionStore;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequest;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;

#[CoversClass(ApplicationBuilder::class)]
#[CoversClass(AuthenticationServiceProvider::class)]
#[UsesClass(Application::class)]
#[UsesClass(ClaimedSlots::class)]
#[UsesClass(SlotClaim::class)]
#[UsesClass(ClaimingExtension::class)]
#[UsesClass(FakeSignIn::class)]
#[UsesClass(RequireSignIn::class)]
#[UsesClass(FrameworkPageServiceProvider::class)]
#[UsesClass(ArraySessionStore::class)]
#[UsesClass(Csrf::class)]
#[UsesClass(VerifyCsrfToken::class)]
#[UsesClass(AdminPage::class)]
#[UsesClass(SignOutController::class)]
#[UsesClass(Identity::class)]
final class SignInSlotTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/guild-framework-sign-in-slot-' . bin2hex(random_bytes(6));
        mkdir($this->basePath);
    }

    protected function tearDown(): void
    {
        rmdir($this->basePath);
    }

    public function testAClaimedSignInMethodIsUsedWithoutAuthenticationConfig(): void
    {
        $signIn = new FakeSignIn();
        $app = Application::configure($this->basePath, [new ClaimingExtension(signIn: $signIn)])
            ->withLogger(new NullLogger())
            ->addAuthentication()
            ->create();

        $router = $app->get(Router::class);
        self::assertInstanceOf(Router::class, $router);
        $router->map('GET', '/private', static fn (): TextResponse => new TextResponse('secret'))
            ->lazyMiddleware(RequireSignIn::class);

        $request = new ServerRequest(uri: '/private', method: 'GET');
        $app->addShared(ServerRequestInterface::class, $request, overwrite: true);
        $response = $router->dispatch($request);

        self::assertSame(302, $response->getStatusCode(), 'a guest is challenged');
        self::assertSame(
            '/fake-sign-in?return=%2Fprivate',
            $response->getHeaderLine('Location'),
            'by the claimed sign-in method',
        );
        self::assertSame($signIn, $app->get(SignInMethod::class), 'SignInMethod resolves to the extension\'s instance');
        self::assertTrue($app->has(RequireSignIn::class), 'and the framework still wires RequireSignIn');
    }

    public function testAClaimedSignInMethodStillMountsSignOut(): void
    {
        $signIn = new FakeSignIn(new Identity('jdoe'));
        $app = Application::configure($this->basePath, [new ClaimingExtension(signIn: $signIn)])
            ->withLogger(new NullLogger())
            ->addAuthentication()
            ->create();

        $request = new ServerRequest(uri: '/framework/sign-out', method: 'POST', parsedBody: ['_csrf' => 't']);
        $app->addShared(ServerRequestInterface::class, $request, overwrite: true);
        $app->addShared(SessionStore::class, new ArraySessionStore(['guild_framework_csrf' => 't']), overwrite: true);

        $router = $app->get(Router::class);
        self::assertInstanceOf(Router::class, $router);
        $response = $router->dispatch($request);

        self::assertTrue($app->isAuthenticationAdded(), 'authentication counts as added');
        self::assertSame('/signed-out', $response->getHeaderLine('Location'), 'POST /framework/sign-out is mounted');
        self::assertNull($signIn->read(), 'and signs jdoe out through the claimed sign-in method');
    }

    public function testTheTakeoverIsLogged(): void
    {
        $log = new TestHandler();

        Application::configure($this->basePath, [new ClaimingExtension(signIn: new FakeSignIn())])
            ->withLogger(new Logger('test', [$log]))
            ->addAuthentication()
            ->create();

        self::assertTrue(
            $log->hasRecord(
                sprintf(
                    'Sign-in method %s was chosen by %s; config/authentication.php was not read.',
                    FakeSignIn::class,
                    ClaimingExtension::class,
                ),
                Level::Notice,
            ),
            'the takeover names the sign-in method and the extension that chose it',
        );
    }

    public function testCasWithAClaimedSignInMethodIsRejected(): void
    {
        $builder = Application::configure($this->basePath, [new ClaimingExtension(signIn: new FakeSignIn())]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(sprintf(
            '%s was chosen as the sign-in method by %s, but addAuthorization() was given IdentitySource::Cas. '
            . 'A chosen sign-in method needs IdentitySource::Oidc and addAuthentication().',
            FakeSignIn::class,
            ClaimingExtension::class,
        ));

        $builder->addAuthorization(IdentitySource::Cas, TestPermission::class);
    }

    public function testWithoutAClaimAMissingAuthenticationConfigStillFails(): void
    {
        $builder = Application::configure($this->basePath, [new ClaimingExtension()]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Problem encountered while attempting to read authentication config file');

        $builder->addAuthentication();
    }
}
