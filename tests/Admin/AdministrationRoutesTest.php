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
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;

/**
 * The administration pages end to end: a CAS application, REMOTE_USER on the
 * request, a fake Grouper, and in-memory SQLite, dispatched through the real
 * Router. Membership is cached in the session, so each test runs in its own
 * process.
 *
 * Integration coverage across many classes, so it declares none; the units
 * are covered by their own tests.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AdministrationRoutesTest extends TestCase
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

    public function testAGuestIsToldTheseUrlsNeedCas(): void
    {
        $response = $this->get('/framework/authorization', null, []);

        self::assertSame(403, $response->getStatusCode(), 'a guest is refused');
        self::assertStringContainsString('must be protected by CAS', (string) $response->getBody(), 'with the fix named');
    }

    public function testAnOrdinaryUserIsRefused(): void
    {
        $response = $this->get('/framework/authorization', 'jdoe', [FakeGrouper::membership(['iu:x-editors' => 'X Editors'])]);

        self::assertSame(403, $response->getStatusCode(), 'membership of other groups is not enough');
        self::assertStringContainsString('Not authorized', (string) $response->getBody());
    }

    public function testAnUnconfirmableAdminGets503NotA403(): void
    {
        $response = $this->get('/framework/authorization', 'jdoe', [FakeGrouper::unavailable()]);

        self::assertSame(503, $response->getStatusCode(), '"could not ask" is not a refusal');
    }

    public function testASystemAdminSeesTheOverviewWithOrphanWarnings(): void
    {
        $response = $this->get('/framework/authorization', 'jdoe', $this->admin(), static function (GrantsDatabase $db): void {
            $db->map($db->group('iu:x-editors'), $db->role('Editor', ['documents.update', 'documents.retired']));
        });
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Groups (1)', $html, 'counts link to each list');
        self::assertStringContainsString('Editor: documents.retired', $html, 'an orphaned grant is called out');
        self::assertStringNotContainsString('Editor: documents.update', $html, 'a defined permission is not');
    }

    public function testTheGroupAndRoleListsEscapeWhatTheyShow(): void
    {
        $seed = static function (GrantsDatabase $db): void {
            $db->map($db->group('iu:x-<b>editors</b>'), $db->role('<script>alert(1)</script>', ['documents.retired']));
        };

        $groups = (string) $this->get('/framework/authorization/groups', 'jdoe', $this->admin(), $seed)->getBody();

        self::assertStringContainsString('iu:x-&lt;b&gt;editors&lt;/b&gt;', $groups, 'the identifier is shown, escaped');
        self::assertStringNotContainsString('<script>alert(1)</script>', $groups, 'nothing stored is emitted as markup');
    }

    public function testTheRoleListFlagsOrphanedGrants(): void
    {
        $html = (string) $this->get('/framework/authorization/roles', 'jdoe', $this->admin(), static function (GrantsDatabase $db): void {
            $db->role('Editor', ['documents.update', 'documents.retired']);
        })->getBody();

        self::assertStringContainsString('documents.retired (not defined by the application)', $html);
        self::assertStringContainsString('documents.update</td>', $html, 'a defined grant is listed plainly');
    }

    public function testPagesUseTheApplicationsLayoutWhenRivetIsAdded(): void
    {
        $html = (string) $this->get('/framework/authorization', 'jdoe', $this->admin(), rivet: true)->getBody();

        self::assertStringContainsString('<title>Authorization · Course Catalog</title>', $html, 'the application\'s title');
        self::assertStringContainsString('>Courses</a>', $html, 'and its navigation');
        self::assertStringContainsString('System settings', $html, 'with the administration menu added');
    }

    public function testWithoutRivetAFallbackLayoutCarriesTheMenuAlone(): void
    {
        $html = (string) $this->get('/framework/authorization', 'jdoe', $this->admin())->getBody();

        self::assertStringContainsString('<title>Authorization · Administration</title>', $html, 'the fallback title');
        self::assertStringContainsString('System settings', $html, 'the menu is the whole navigation');
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
        $builder = Application::configure($this->basePath)->withLogger(new NullLogger())->addIlluminateDatabase();

        if ($rivet) {
            $builder = $builder->addTemplateEngine(TemplateEngine::Twig)->addRivet(new PageDefaults(
                appTitle: 'Course Catalog',
                navItems: [['label' => 'Courses', 'href' => '/courses']],
            ));
        }

        $app = $builder->addAuthorization(IdentitySource::Cas, TestPermission::class)->create();

        $request = new ServerRequest(
            serverParams: $remoteUser === null ? [] : ['REMOTE_USER' => $remoteUser],
            uri: $path,
            method: 'GET',
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

        return $router->dispatch($request);
    }
}
