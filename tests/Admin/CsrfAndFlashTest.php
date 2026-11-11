<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Admin;

use Guild\Framework\Testing\ArraySessionStore;
use Guild\Framework\Admin\AdminPage;
use Guild\Framework\Admin\Csrf;
use Guild\Framework\Admin\Flash;
use Guild\Framework\Middleware\VerifyCsrfToken;
use Guild\Framework\Session\SessionStore;
use Guild\Rivet\Page\PageDefaults;
use Guild\Rivet\Render\Renderer;
use Guild\Rivet\Rivet;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(Csrf::class)]
#[CoversClass(Flash::class)]
#[CoversClass(VerifyCsrfToken::class)]
#[UsesClass(AdminPage::class)]
#[UsesClass(ArraySessionStore::class)]
final class CsrfAndFlashTest extends TestCase
{
    public function testTheTokenIsStableForTheSessionAndChecked(): void
    {
        $csrf = new Csrf(new ArraySessionStore());
        $token = $csrf->token();

        self::assertSame(64, strlen($token), '32 random bytes, hex encoded');
        self::assertSame($token, $csrf->token(), 'one token per session');
        self::assertTrue($csrf->isValid($token), 'the issued token is accepted');
        self::assertFalse($csrf->isValid('x' . substr($token, 1)), 'anything else is not');
        self::assertFalse($csrf->isValid(null), 'nor is a missing field');
        self::assertStringContainsString('name="_csrf" value="' . $token . '"', $csrf->field()->render(), 'the hidden field carries it');
    }

    public function testAnExistingSessionTokenIsKept(): void
    {
        self::assertSame(
            'abc',
            new Csrf(new ArraySessionStore(['guild_framework_csrf' => 'abc']))->token(),
            'forms issued before an upgrade still validate',
        );
    }

    public function testRotatingIssuesANewToken(): void
    {
        $csrf = new Csrf(new ArraySessionStore());
        $before = $csrf->token();

        $csrf->rotate();

        self::assertNotSame($before, $csrf->token(), 'the next form gets a new token');
        self::assertFalse($csrf->isValid($before), 'and a form issued before the rotation no longer validates');
    }

    public function testSafeMethodsPassWithoutAToken(): void
    {
        $response = $this->middleware()->process(new ServerRequest(method: 'GET'), $this->handler());

        self::assertSame(204, $response->getStatusCode(), 'reading a page needs no token');
    }

    public function testAPostWithoutTheTokenIsRefused(): void
    {
        $response = $this->middleware()->process(
            new ServerRequest(method: 'POST', parsedBody: ['name' => 'Editor']),
            $this->handler(),
        );

        self::assertSame(403, $response->getStatusCode(), 'a forged or stale form is refused');
        self::assertStringContainsString('Form expired', (string) $response->getBody());
    }

    public function testAPostWithTheTokenPasses(): void
    {
        $session = new ArraySessionStore();
        $token = new Csrf($session)->token();

        $response = $this->middleware($session)->process(
            new ServerRequest(method: 'POST', parsedBody: [Csrf::FIELD => $token]),
            $this->handler(),
        );

        self::assertSame(204, $response->getStatusCode(), 'the session\'s own form is accepted');
    }

    public function testAFlashMessageIsReadOnce(): void
    {
        $flash = new Flash(new ArraySessionStore());
        $flash->set('Saved.');

        self::assertSame('Saved.', $flash->take(), 'the message survives until read');
        self::assertNull($flash->take(), 'and is gone after');
    }

    private function middleware(SessionStore $session = new ArraySessionStore()): VerifyCsrfToken
    {
        return new VerifyCsrfToken(
            new Csrf($session),
            new AdminPage(static fn (): Renderer => new Renderer(Rivet::registry(), pageDefaults: new PageDefaults(appTitle: 'Test'))),
        );
    }

    private function handler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new EmptyResponse();
            }
        };
    }
}
