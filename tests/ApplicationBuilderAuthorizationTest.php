<?php

declare(strict_types=1);

namespace Guild\Framework\Test;

use Guild\Framework\Application;
use Guild\Framework\ApplicationBuilder;
use Guild\Framework\Authorization\AdminMenu;
use Guild\Framework\Authorization\AdminNavigation;
use Guild\Framework\Authorization\AuthorizationConfiguration;
use Guild\Framework\Authorization\Gate;
use Guild\Framework\Authorization\GrantRepository;
use Guild\Framework\Authorization\Identity\IdentityReader;
use Guild\Framework\Authorization\Identity\OidcIdentityReader;
use Guild\Framework\Authorization\Identity\RemoteUserIdentityReader;
use Guild\Framework\Authorization\IdentitySource;
use Guild\Framework\Authorization\Membership\MembershipCache;
use Guild\Framework\Authorization\Membership\MembershipProvider;
use Guild\Framework\Authorization\Policy\PolicyRegistry;
use Guild\Framework\Authorization\UserResolver;
use Guild\Framework\Exception\ConfigurationException;
use Guild\Framework\ServiceProvider\AuthenticationServiceProvider;
use Guild\Framework\ServiceProvider\AuthorizationServiceProvider;
use Guild\Framework\ServiceProvider\AuthorizationTemplateServiceProvider;
use Guild\Framework\ServiceProvider\RivetServiceProvider;
use Guild\Framework\Rivet\ContainerNavigation;
use Guild\Rivet\Page\NavigationProvider;
use Guild\Rivet\Page\PageDefaults;
use Guild\Rivet\Render\Renderer;
use Guild\Framework\ServiceProvider\ViewServiceProvider;
use Guild\Framework\TemplateEngine;
use Guild\Framework\View;
use Guild\Framework\Authorization\TemplateAuthorization;
use Guild\Framework\Twig\AuthorizationExtension as TwigAuthorizationExtension;
use Guild\Framework\Latte\AuthorizationExtension as LatteAuthorizationExtension;
use Guild\Framework\Test\Authorization\Support\Policy\DocumentPolicy;
use Guild\Framework\Test\Authorization\Support\TestPermission;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

#[CoversClass(ApplicationBuilder::class)]
#[CoversClass(Application::class)]
#[CoversClass(AuthorizationServiceProvider::class)]
#[CoversClass(AuthorizationTemplateServiceProvider::class)]
#[UsesClass(ViewServiceProvider::class)]
#[UsesClass(RivetServiceProvider::class)]
#[UsesClass(ContainerNavigation::class)]
#[UsesClass(AdminMenu::class)]
#[UsesClass(AdminNavigation::class)]
#[UsesClass(View::class)]
#[UsesClass(TemplateAuthorization::class)]
#[UsesClass(TwigAuthorizationExtension::class)]
#[UsesClass(LatteAuthorizationExtension::class)]
#[UsesClass(AuthenticationServiceProvider::class)]
#[UsesClass(AuthorizationConfiguration::class)]
#[UsesClass(UserResolver::class)]
#[UsesClass(RemoteUserIdentityReader::class)]
#[UsesClass(OidcIdentityReader::class)]
#[UsesClass(MembershipProvider::class)]
#[UsesClass(MembershipCache::class)]
#[UsesClass(GrantRepository::class)]
#[UsesClass(Gate::class)]
#[UsesClass(PolicyRegistry::class)]
final class ApplicationBuilderAuthorizationTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/guild-framework-test-' . bin2hex(random_bytes(6));
        mkdir($this->basePath . '/config', recursive: true);
        mkdir($this->basePath . '/templates');
        file_put_contents($this->basePath . '/templates/check.twig', "{{ cannot('documents.update') ? 'guest' : 'user' }}");
        file_put_contents($this->basePath . '/templates/check.latte', "{cannot('documents.update') ? 'guest' : 'user'}");

        $this->writeConfig('database.php', "return ['driver' => 'sqlite', 'database' => ':memory:'];");
        $this->writeConfig('authentication.php', <<<'PHP'
            return new Guild\Access\Authentication\OIDC\OidcConfiguration(
                'https://idp.login.iu.edu', 'id', 'secret', 'https://app.iu.edu/cb',
            );
            PHP);
        $this->writeConfig('authorization.php', <<<'PHP'
            return new Guild\Framework\Authorization\AuthorizationConfiguration(
                new Guild\Grouper\GrouperConfiguration('https://grouper.example.edu/ws', 'svc', 'secret'),
                'My App Admins',
            );
            PHP);
    }

    protected function tearDown(): void
    {
        foreach ([...(glob($this->basePath . '/config/*') ?: []), ...(glob($this->basePath . '/templates/*') ?: [])] as $file) {
            unlink($file);
        }

        rmdir($this->basePath . '/config');
        rmdir($this->basePath . '/templates');
        rmdir($this->basePath);
    }

    public function testACasApplicationGetsAUserResolverWithoutAuthentication(): void
    {
        $app = Application::configure($this->basePath)
            ->addIlluminateDatabase()
            ->addAuthorization(IdentitySource::Cas, TestPermission::class)
            ->create();

        self::assertInstanceOf(UserResolver::class, $app->get(UserResolver::class), 'the resolver is registered');
    }

    public function testTheGateIsRegisteredWithTheListedPolicies(): void
    {
        $app = Application::configure($this->basePath)
            ->enableAutoWiring()
            ->addIlluminateDatabase()
            ->addAuthorization(IdentitySource::Cas, TestPermission::class, policies: [DocumentPolicy::class])
            ->create();

        self::assertInstanceOf(Gate::class, $app->get(Gate::class), 'the gate is registered');
        self::assertSame($app->get(Gate::class), $app->get(Gate::class), 'and shared');
    }

    /**
     * @return iterable<string, array{TemplateEngine, string, bool}>
     */
    public static function engineOrderings(): iterable
    {
        yield 'Twig, engine first' => [TemplateEngine::Twig, 'check.twig', true];
        yield 'Twig, authorization first' => [TemplateEngine::Twig, 'check.twig', false];
        yield 'Latte, engine first' => [TemplateEngine::Latte, 'check.latte', true];
        yield 'Latte, authorization first' => [TemplateEngine::Latte, 'check.latte', false];
    }

    #[DataProvider('engineOrderings')]
    public function testTemplatesGetCanAndCannotInEitherOrder(TemplateEngine $engine, string $template, bool $engineFirst): void
    {
        $builder = Application::configure($this->basePath)->addIlluminateDatabase();

        $builder = $engineFirst
            ? $builder->addTemplateEngine($engine)->addAuthorization(IdentitySource::Cas, TestPermission::class)
            : $builder->addAuthorization(IdentitySource::Cas, TestPermission::class)->addTemplateEngine($engine);

        $view = $builder->create()->get(View::class);

        self::assertInstanceOf(View::class, $view, 'the view is registered');
        self::assertSame('guest', $view->render($template), 'cannot() is available and a CLI request is a guest');
    }

    public function testTheAdminMenuIsBoundUnlessTheApplicationOptsOut(): void
    {
        $withMenu = Application::configure($this->basePath)
            ->addIlluminateDatabase()
            ->addAuthorization(IdentitySource::Cas, TestPermission::class)
            ->create();
        $withoutMenu = Application::configure($this->basePath)
            ->addIlluminateDatabase()
            ->addAuthorization(IdentitySource::Cas, TestPermission::class, adminMenu: null)
            ->create();

        self::assertInstanceOf(AdminNavigation::class, $withMenu->get(NavigationProvider::class), 'the menu is on by default');
        self::assertFalse($withoutMenu->has(NavigationProvider::class), 'null leaves the header alone');
    }

    public function testRivetPagesAskTheAuthorizationLayerEvenWhenItIsAddedAfterRivet(): void
    {
        $defaults = new PageDefaults(appTitle: 'Course Catalog', navItems: [['label' => 'Courses', 'href' => '/courses']]);

        $app = Application::configure($this->basePath)
            ->addIlluminateDatabase()
            ->addTemplateEngine(TemplateEngine::Twig)
            ->addRivet($defaults)
            ->addAuthorization(IdentitySource::Cas, TestPermission::class)
            ->create();

        $renderer = $app->get(Renderer::class);

        self::assertInstanceOf(Renderer::class, $renderer, 'Rivet is registered');
        self::assertSame(
            $defaults->navItems,
            $renderer->context()->navItems($defaults),
            'a CLI request is a guest, so the menu is left out and nothing throws',
        );
    }

    public function testAnOidcApplicationMustAddAuthenticationFirst(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('addAuthentication() has not been called');

        Application::configure($this->basePath)->addAuthorization(IdentitySource::Oidc, TestPermission::class);
    }

    public function testAnOidcApplicationWithAuthenticationIsAccepted(): void
    {
        $app = Application::configure($this->basePath)
            ->addIlluminateDatabase()
            ->addAuthentication()
            ->addAuthorization(IdentitySource::Oidc, TestPermission::class)
            ->create();

        self::assertInstanceOf(UserResolver::class, $app->get(UserResolver::class), 'the resolver is registered');
        self::assertInstanceOf(
            OidcIdentityReader::class,
            $app->get(IdentityReader::class),
            'identity comes from guild/access, whose services now resolve because a logger is bound',
        );
    }

    public function testAConfigFileReturningTheWrongTypeThrows(): void
    {
        $this->writeConfig('authorization.php', "return ['system_admin_group' => 'My App Admins'];");

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('must return an instance of AuthorizationConfiguration');

        Application::configure($this->basePath)->addAuthorization(IdentitySource::Cas, TestPermission::class);
    }

    public function testInvalidGrouperSettingsFailAtStartup(): void
    {
        $this->writeConfig('authorization.php', <<<'PHP'
            return new Guild\Framework\Authorization\AuthorizationConfiguration(
                new Guild\Grouper\GrouperConfiguration('http://grouper.example.edu/ws', 'svc', 'secret'),
                'My App Admins',
            );
            PHP);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('authorization config file');

        Application::configure($this->basePath)->addAuthorization(IdentitySource::Cas, TestPermission::class);
    }

    public function testTheDefaultLoggerIsTheFrameworkMonologChannel(): void
    {
        $logger = Application::configure($this->basePath)->create()->get(LoggerInterface::class);

        self::assertInstanceOf(Logger::class, $logger, 'a logger is bound without the application doing anything');
        self::assertSame('framework', $logger->getName(), 'on the framework channel');
    }

    public function testWithLoggerReplacesTheDefault(): void
    {
        $logger = new NullLogger();

        $app = Application::configure($this->basePath)->withLogger($logger)->create();

        self::assertSame($logger, $app->get(LoggerInterface::class), 'the application\'s logger wins');
    }

    private function writeConfig(string $file, string $body): void
    {
        file_put_contents($this->basePath . '/config/' . $file, "<?php\n\n" . $body . "\n");
    }
}
