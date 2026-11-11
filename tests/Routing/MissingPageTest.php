<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Routing;

use Guild\Framework\Application;
use Guild\Framework\Router;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * A request no route answers, driven through an application's real Router:
 * an address with no page is a 404 page, and a page asked with a method it
 * doesn't take is a 405, both inside the router's own middleware.
 *
 * Integration coverage across the Application, Router and status page, so it
 * declares none.
 */
#[CoversNothing]
final class MissingPageTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/guild-framework-missing-page-' . bin2hex(random_bytes(6));
        mkdir($this->basePath . '/routes', recursive: true);
        file_put_contents($this->basePath . '/routes/routes.php', <<<'PHP'
            <?php

            return static function (Guild\Framework\Router $router): void {
                $router->map('GET', '/documents', static fn (): Psr\Http\Message\ResponseInterface => new Laminas\Diactoros\Response\TextResponse('documents'));
                // By class name, as applications and the framework register router middleware.
                $router->lazyMiddleware(Guild\Framework\Test\Routing\Support\MarksResponses::class);
            };
            PHP);
    }

    protected function tearDown(): void
    {
        unlink($this->basePath . '/routes/routes.php');
        rmdir($this->basePath . '/routes');
        rmdir($this->basePath);
    }

    public function testAnAddressWithNoPageIsNotFound(): void
    {
        $response = $this->send('GET', '/nowhere');

        self::assertSame(404, $response->getStatusCode(), 'no route answers this address');
        self::assertStringContainsString('<h1>Page not found</h1>', (string) $response->getBody(), 'the page says so');
    }

    public function testAPageAskedWithTheWrongMethodIsNotAllowed(): void
    {
        $response = $this->send('POST', '/documents');

        self::assertSame(405, $response->getStatusCode(), 'the page exists, but not for POST');
        self::assertSame('GET', $response->getHeaderLine('Allow'), 'the response names the methods it takes');
        self::assertStringContainsString('<h1>Method not allowed</h1>', (string) $response->getBody(), 'the page says so');
    }

    public function testTheRoutersMiddlewareWrapsBothPages(): void
    {
        self::assertSame('yes', $this->send('GET', '/nowhere')->getHeaderLine(Support\MarksResponses::HEADER), 'a 404 passes through router middleware, such as a banner');
        self::assertSame('yes', $this->send('POST', '/documents')->getHeaderLine(Support\MarksResponses::HEADER), 'so does a 405');
        self::assertSame('yes', $this->send('GET', '/documents')->getHeaderLine(Support\MarksResponses::HEADER), 'as a page that exists does');
    }

    public function testAPageThatExistsStillAnswers(): void
    {
        $response = $this->send('GET', '/documents');

        self::assertSame(200, $response->getStatusCode(), 'the route answers');
        self::assertSame('documents', (string) $response->getBody(), 'with its own response');
    }

    private function send(string $method, string $path): ResponseInterface
    {
        $app = Application::configure($this->basePath)->enableAutoWiring()->addRouting()->create();
        $router = $app->get(Router::class);
        self::assertInstanceOf(Router::class, $router);

        return $router->dispatch(new ServerRequest(method: $method, uri: 'http://localhost' . $path));
    }
}
