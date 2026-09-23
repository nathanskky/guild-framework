<?php

declare(strict_types=1);

namespace Guild\Framework\Test;

use Guild\Framework\Application;
use Guild\Framework\ApplicationBuilder;
use Guild\Framework\Authorization\AuthorizationConfiguration;
use Guild\Framework\Authorization\GrantRepository;
use Guild\Framework\Authorization\Identity\IdentityReader;
use Guild\Framework\Authorization\Identity\OidcIdentityReader;
use Guild\Framework\Authorization\Identity\RemoteUserIdentityReader;
use Guild\Framework\Authorization\IdentitySource;
use Guild\Framework\Authorization\Membership\MembershipCache;
use Guild\Framework\Authorization\Membership\MembershipProvider;
use Guild\Framework\Authorization\UserResolver;
use Guild\Framework\Exception\ConfigurationException;
use Guild\Framework\ServiceProvider\AuthenticationServiceProvider;
use Guild\Framework\ServiceProvider\AuthorizationServiceProvider;
use Guild\Framework\Test\Authorization\Support\TestPermission;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

#[CoversClass(ApplicationBuilder::class)]
#[CoversClass(Application::class)]
#[CoversClass(AuthorizationServiceProvider::class)]
#[UsesClass(AuthenticationServiceProvider::class)]
#[UsesClass(AuthorizationConfiguration::class)]
#[UsesClass(UserResolver::class)]
#[UsesClass(RemoteUserIdentityReader::class)]
#[UsesClass(OidcIdentityReader::class)]
#[UsesClass(MembershipProvider::class)]
#[UsesClass(MembershipCache::class)]
#[UsesClass(GrantRepository::class)]
final class ApplicationBuilderAuthorizationTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/guild-framework-test-' . bin2hex(random_bytes(6));
        mkdir($this->basePath . '/config', recursive: true);

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
        foreach (glob($this->basePath . '/config/*.php') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->basePath . '/config');
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
