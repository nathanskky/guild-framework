<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Extension;

use Guild\Framework\Application;
use Guild\Framework\Authorization\Identity\Identity;
use Guild\Framework\Authorization\IdentitySource;
use Guild\Framework\Router;
use Guild\Framework\Session\SessionStore;
use Guild\Framework\Test\Authentication\Support\FakeSignIn;
use Guild\Framework\Test\Authorization\Support\GrantsDatabase;
use Guild\Framework\Test\Authorization\Support\TestPermission;
use Guild\Framework\Test\Extension\Support\ArrayGroupDirectory;
use Guild\Framework\Test\Extension\Support\ClaimingExtension;
use Guild\Framework\Testing\ArraySessionStore;
use Guild\Grouper\GrouperGroup;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;

/**
 * An application whose extension claims all three slots, booted without
 * config/authentication.php, config/grouper.php or a System Admin label in
 * config/authorization.php, and driven through the real Router.
 *
 * Integration coverage across many classes, so it declares none; the units
 * are covered by their own tests.
 */
#[CoversNothing]
final class ExtensionAcceptanceTest extends TestCase
{
    private const string ADMIN_LABEL = 'Starter Admins';

    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/guild-framework-extension-acceptance-' . bin2hex(random_bytes(6));
        mkdir($this->basePath . '/config', recursive: true);
        mkdir($this->basePath . '/routes');
        file_put_contents($this->basePath . '/config/database.php', "<?php\n\nreturn ['driver' => 'sqlite', 'database' => ':memory:'];\n");
        file_put_contents($this->basePath . '/config/authorization.php', <<<'PHP'
            <?php

            return new Guild\Framework\Authorization\AuthorizationConfiguration();
            PHP);
        file_put_contents($this->basePath . '/routes/routes.php', <<<'PHP'
            <?php

            return static function (Guild\Framework\Router $router): void {
                $router->map('GET', '/documents', [Guild\Framework\Test\Extension\Support\DocumentsController::class, 'index'])
                    ->lazyMiddleware(Guild\Framework\Middleware\RequireSignIn::class);
            };
            PHP);
    }

    protected function tearDown(): void
    {
        foreach (['config', 'routes'] as $directory) {
            foreach (glob($this->basePath . '/' . $directory . '/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($this->basePath . '/' . $directory);
        }

        rmdir($this->basePath);
    }

    public function testAnExtensionClaimingAllThreeSlotsBootsWithoutCredentials(): void
    {
        $guest = $this->get('/documents', null);

        self::assertSame(302, $guest->getStatusCode(), 'a guest is challenged');
        self::assertSame('/fake-sign-in?return=%2Fdocuments', $guest->getHeaderLine('Location'), 'by the claimed sign-in method');

        $withoutGrant = $this->get('/documents', 'jdoe');

        self::assertSame(403, $withoutGrant->getStatusCode(), 'a signed-in user without the grant is refused');
        self::assertStringContainsString('Not authorized', (string) $withoutGrant->getBody(), 'with the framework\'s page');

        $withGrant = $this->get('/documents', 'jdoe', static function (GrantsDatabase $db): void {
            $db->map($db->group('iu:x-readers'), $db->role('Reader', ['documents.view']));
        });

        self::assertSame(200, $withGrant->getStatusCode(), 'a member of a group whose role grants the permission is let in');

        $admin = $this->get('/documents', 'admin');

        self::assertSame(200, $admin->getStatusCode(), 'a member of the claimed System Admin group is let in');
        self::assertSame('documents', (string) $admin->getBody(), 'to the application\'s page');

        $adminPages = $this->get('/framework/authorization', 'admin');

        self::assertSame(200, $adminPages->getStatusCode(), 'the administration pages open for that member');
        self::assertStringContainsString('Authorization', (string) $adminPages->getBody(), 'and show the overview');

        $ordinary = $this->get('/framework/authorization', 'jdoe');

        self::assertSame(403, $ordinary->getStatusCode(), 'while an ordinary user is refused them');
    }

    /**
     * @param  (callable(GrantsDatabase): void)|null  $seed
     */
    private function get(string $path, ?string $username, ?callable $seed = null): ResponseInterface
    {
        $signIn = new FakeSignIn($username === null ? null : new Identity($username));
        $directory = new ArrayGroupDirectory([
            'jdoe' => [new GrouperGroup('iu:x-readers', 'Indiana University:X:Readers', 'X Readers', 'uuid-1')],
            'admin' => [new GrouperGroup('iu:x-admins', 'Indiana University:X:Admins', self::ADMIN_LABEL, 'uuid-2')],
        ]);

        $app = Application::configure($this->basePath, [new ClaimingExtension($signIn, $directory, self::ADMIN_LABEL)])
            ->withLogger(new NullLogger())
            ->enableAutoWiring()
            ->addIlluminateDatabase()
            ->addAuthentication()
            ->addAuthorization(IdentitySource::Oidc, TestPermission::class)
            ->addRouting()
            ->create();

        $request = new ServerRequest(uri: $path, method: 'GET');
        $app->addShared(ServerRequestInterface::class, $request, overwrite: true);
        $app->addShared(SessionStore::class, new ArraySessionStore(), overwrite: true);

        $capsule = $app->get(Capsule::class);
        self::assertInstanceOf(Capsule::class, $capsule);
        $connection = $capsule->getConnection();
        self::assertInstanceOf(Connection::class, $connection);
        $db = new GrantsDatabase($connection);

        if ($seed !== null) {
            $seed($db);
        }

        $router = $app->get(Router::class);
        self::assertInstanceOf(Router::class, $router);

        return $router->dispatch($request);
    }
}
