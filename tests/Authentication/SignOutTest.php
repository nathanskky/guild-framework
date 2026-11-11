<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authentication;

use Guild\Framework\Admin\Csrf;
use Guild\Framework\Authorization\StatusPage;
use Guild\Framework\Application;
use Guild\Framework\ApplicationBuilder;
use Guild\Framework\Authentication\SignInMethod;
use Guild\Framework\Authorization\Identity\Identity;
use Guild\Framework\Controller\Authentication\SignOutController;
use Guild\Framework\Middleware\VerifyCsrfToken;
use Guild\Framework\Router;
use Guild\Framework\ServiceProvider\AuthenticationServiceProvider;
use Guild\Framework\ServiceProvider\FrameworkPageServiceProvider;
use Guild\Framework\Session\SessionStore;
use Guild\Framework\Test\Authentication\Support\FakeSignIn;
use Guild\Framework\Testing\ArraySessionStore;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;

/**
 * Sign-out stands alone: these applications call addAuthentication() and
 * nothing else, so no authorization layer and no Rivet.
 */
#[CoversClass(SignOutController::class)]
#[CoversClass(ApplicationBuilder::class)]
#[UsesClass(Application::class)]
#[UsesClass(AuthenticationServiceProvider::class)]
#[UsesClass(FrameworkPageServiceProvider::class)]
#[UsesClass(Csrf::class)]
#[UsesClass(VerifyCsrfToken::class)]
#[UsesClass(StatusPage::class)]
#[UsesClass(FakeSignIn::class)]
#[UsesClass(ArraySessionStore::class)]
#[UsesClass(Identity::class)]
final class SignOutTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/guild-framework-sign-out-' . bin2hex(random_bytes(6));
        mkdir($this->basePath . '/config', recursive: true);
        file_put_contents($this->basePath . '/config/authentication.php', <<<'PHP'
            <?php

            return new Guild\Access\Authentication\OIDC\OidcConfiguration(
                'https://idp.login.iu.edu', 'id', 'secret', 'https://app.iu.edu/cb',
            );
            PHP);
    }

    protected function tearDown(): void
    {
        unlink($this->basePath . '/config/authentication.php');
        rmdir($this->basePath . '/config');
        rmdir($this->basePath);
    }

    public function testPostingTheFormSignsOut(): void
    {
        $signIn = new FakeSignIn(new Identity('jdoe'));

        $response = $this->dispatch('POST', ['_csrf' => 't'], $signIn);

        self::assertSame(302, $response->getStatusCode(), 'jdoe is redirected');
        self::assertSame('/signed-out', $response->getHeaderLine('Location'), 'to where the sign-in method says');
        self::assertNull($signIn->read(), 'and is no longer signed in');
    }

    public function testSigningOutRotatesTheCsrfToken(): void
    {
        $session = new ArraySessionStore(['guild_framework_csrf' => 't']);

        $this->dispatch('POST', ['_csrf' => 't'], new FakeSignIn(), $session);

        self::assertNotSame('t', $session->get('guild_framework_csrf'), 'a token known before signing out is worthless after');
    }

    public function testAMissingTokenIsRefused(): void
    {
        $signIn = new FakeSignIn(new Identity('jdoe'));

        $response = $this->dispatch('POST', [], $signIn);

        self::assertSame(403, $response->getStatusCode(), 'a form without the session token is refused');
        self::assertSame('jdoe', $signIn->read()?->username, 'and jdoe is still signed in');
    }

    public function testAnExpiredFormIsExplainedWithoutAdministrationChrome(): void
    {
        $body = (string) $this->dispatch('POST', ['_csrf' => 'other'], new FakeSignIn(new Identity('jdoe')))->getBody();

        self::assertStringContainsString('Form expired', $body, 'the page says why the form was refused');
        self::assertStringNotContainsString('Administration', $body, 'in an application that has no administration pages');
        self::assertStringNotContainsString('/framework/authorization', $body, 'and without linking to them');
    }

    public function testAWrongTokenIsRefused(): void
    {
        $signIn = new FakeSignIn(new Identity('jdoe'));

        $response = $this->dispatch('POST', ['_csrf' => 'other'], $signIn);

        self::assertSame(403, $response->getStatusCode(), 'a token from another session is refused');
        self::assertSame('jdoe', $signIn->read()?->username, 'and jdoe is still signed in');
    }

    public function testGetIsNotAllowed(): void
    {
        $signIn = new FakeSignIn(new Identity('jdoe'));

        $response = $this->dispatch('GET', null, $signIn);

        self::assertSame(405, $response->getStatusCode(), 'a link cannot sign anyone out');
        self::assertSame('POST', $response->getHeaderLine('Allow'), 'only a form can');
        self::assertSame('jdoe', $signIn->read()?->username, 'and jdoe is still signed in');
    }

    /**
     * @param  array<string, string>|null  $form
     */
    private function dispatch(string $method, ?array $form, SignInMethod $signIn, ?ArraySessionStore $session = null): ResponseInterface
    {
        $app = Application::configure($this->basePath)->withLogger(new NullLogger())->addAuthentication()->create();

        $request = new ServerRequest(uri: '/framework/sign-out', method: $method, parsedBody: $form);

        // Registered before anything resolves them, so these win.
        $app->addShared(ServerRequestInterface::class, $request, overwrite: true);
        $app->addShared(SessionStore::class, $session ?? new ArraySessionStore(['guild_framework_csrf' => 't']), overwrite: true);
        $app->addShared(SignInMethod::class, $signIn, overwrite: true);

        $router = $app->get(Router::class);
        self::assertInstanceOf(Router::class, $router);

        return $router->dispatch($request);
    }
}
