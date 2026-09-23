<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Admin;

use Guild\Framework\Application;
use Guild\Framework\Authorization\IdentitySource;
use Guild\Framework\Router;
use Guild\Framework\TemplateEngine;
use Guild\Framework\Test\Authorization\Support\FakeGrouper;
use Guild\Framework\Test\Authorization\Support\GrantsDatabase;
use Guild\Framework\Test\Authorization\Support\TestPermission;
use Guild\Grouper\GrouperClient;
use Guild\Rivet\Page\PageDefaults;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Laminas\Diactoros\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;

/**
 * Builds a CAS application with a fake Grouper and in-memory SQLite, and
 * dispatches one request through the real Router. The authorization
 * configuration names 'My App Admins' as the System Admin label and uses the
 * default ACM stem, iu:roles:sys:acm.
 */
trait DispatchesAdministration
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/guild-framework-admin-' . bin2hex(random_bytes(6));
        mkdir($this->basePath . '/config', recursive: true);
        mkdir($this->basePath . '/templates');
        file_put_contents($this->basePath . '/config/database.php', "<?php\n\nreturn ['driver' => 'sqlite', 'database' => ':memory:'];\n");
        file_put_contents($this->basePath . '/config/authorization.php', <<<'PHP'
            <?php

            return new Guild\Framework\Authorization\AuthorizationConfiguration(
                new Guild\Grouper\GrouperConfiguration('https://grouper.example.edu/ws', 'svc', 'secret'),
                'My App Admins',
            );
            PHP);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->basePath . '/config/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->basePath . '/config');
        rmdir($this->basePath . '/templates');
        rmdir($this->basePath);
    }

    /**
     * @return list<ResponseInterface>
     */
    private function admin(): array
    {
        return [FakeGrouper::membership(['iu:x-admins' => 'My App Admins'])];
    }

    /**
     * @param  list<ResponseInterface>  $grouper
     * @param  (callable(GrantsDatabase): void)|null  $seed
     */
    private function get(string $path, ?string $remoteUser, array $grouper, ?callable $seed = null, bool $rivet = false): ResponseInterface
    {
        return $this->dispatch('GET', $path, $remoteUser, $grouper, $seed, $rivet)[0];
    }

    /**
     * Dispatch one request and return the response with the database, so a
     * test can inspect what was written.
     *
     * @param  list<ResponseInterface>  $grouper
     * @param  (callable(GrantsDatabase): void)|null  $seed
     * @param  array<string, mixed>|null  $form  a POST body; the session's CSRF token is added unless $csrf is false
     * @return array{ResponseInterface, GrantsDatabase}
     */
    private function dispatch(
        string $method,
        string $path,
        ?string $remoteUser,
        array $grouper,
        ?callable $seed = null,
        bool $rivet = false,
        ?array $form = null,
        bool $csrf = true,
    ): array {
        $builder = Application::configure($this->basePath)->withLogger(new NullLogger())->addIlluminateDatabase();

        if ($rivet) {
            $builder = $builder->addTemplateEngine(TemplateEngine::Twig)->addRivet(new PageDefaults(
                appTitle: 'Course Catalog',
                navItems: [['label' => 'Courses', 'href' => '/courses']],
            ));
        }

        $app = $builder->addAuthorization(IdentitySource::Cas, TestPermission::class)->create();

        if ($form !== null && $csrf) {
            session_start();
            $_SESSION['guild_framework_csrf'] = 'test-token';
            $form['_csrf'] = 'test-token';
        }

        $request = new ServerRequest(
            serverParams: $remoteUser === null ? [] : ['REMOTE_USER' => $remoteUser],
            uri: $path,
            method: $method,
            parsedBody: $form,
        );

        // Registered before the lazy provider first resolves, so these win.
        $app->addShared(GrouperClient::class, new FakeGrouper($grouper)->client);
        $app->addShared(ServerRequestInterface::class, $request, overwrite: true);

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

        return [$router->dispatch($request), $db];
    }
}
