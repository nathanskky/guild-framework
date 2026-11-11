<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Extension;

use Guild\Framework\Application;
use Guild\Framework\ApplicationBuilder;
use Guild\Framework\Authorization\Identity\Identity;
use Guild\Framework\Authorization\Identity\RemoteUserIdentityReader;
use Guild\Framework\Authorization\IdentitySource;
use Guild\Framework\Authorization\Membership\MembershipCache;
use Guild\Framework\Authorization\Membership\MembershipProvider;
use Guild\Framework\Authorization\AuthorizationConfiguration;
use Guild\Framework\Authorization\GrantRepository;
use Guild\Framework\Authorization\User;
use Guild\Framework\Authorization\UserResolver;
use Guild\Framework\Exception\ConfigurationException;
use Guild\Framework\Extension\ClaimedSlots;
use Guild\Framework\Extension\SlotClaim;
use Guild\Framework\ServiceProvider\AuthorizationServiceProvider;
use Guild\Framework\Session\SessionStore;
use Guild\Framework\Test\Authorization\Support\TestPermission;
use Guild\Framework\Test\Extension\Support\ArrayGroupDirectory;
use Guild\Framework\Test\Extension\Support\ClaimingExtension;
use Guild\Framework\Testing\ArraySessionStore;
use Guild\Grouper\GrouperGroup;
use Laminas\Diactoros\ServerRequestFactory;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;

#[CoversClass(ApplicationBuilder::class)]
#[CoversClass(AuthorizationServiceProvider::class)]
#[CoversClass(AuthorizationConfiguration::class)]
#[UsesClass(Application::class)]
#[UsesClass(ClaimedSlots::class)]
#[UsesClass(SlotClaim::class)]
#[UsesClass(ClaimingExtension::class)]
#[UsesClass(ArrayGroupDirectory::class)]
#[UsesClass(MembershipProvider::class)]
#[UsesClass(MembershipCache::class)]
#[UsesClass(ArraySessionStore::class)]
#[UsesClass(UserResolver::class)]
#[UsesClass(User::class)]
#[UsesClass(Identity::class)]
#[UsesClass(RemoteUserIdentityReader::class)]
#[UsesClass(GrantRepository::class)]
final class SystemAdminSlotTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/guild-framework-system-admin-slot-' . bin2hex(random_bytes(6));
        mkdir($this->basePath . '/config', recursive: true);
        file_put_contents($this->basePath . '/config/database.php', "<?php return ['driver' => 'sqlite', 'database' => ':memory:'];");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->basePath . '/config/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->basePath . '/config');
        rmdir($this->basePath);
    }

    public function testAClaimedLabelMakesItsMembersSystemAdmins(): void
    {
        $this->writeAuthorizationConfig('new Guild\Framework\Authorization\AuthorizationConfiguration()');

        self::assertTrue(
            $this->isSystemAdmin(new ClaimingExtension(
                directory: $this->directoryWith('Starter Admins'),
                systemAdminGroup: 'Starter Admins',
            )),
            'a member of the claimed group is a System Admin although the config label is blank',
        );
    }

    public function testAClaimOverridesTheConfiguredLabelAndSaysSo(): void
    {
        $this->writeAuthorizationConfig("new Guild\\Framework\\Authorization\\AuthorizationConfiguration('My App Admins')");
        $log = new TestHandler();
        $extension = new ClaimingExtension(
            directory: $this->directoryWith('My App Admins'),
            systemAdminGroup: 'Starter Admins',
        );

        $app = $this->bootApp($extension, new Logger('test', [$log]));

        self::assertFalse($this->resolveAdmin($app), 'a member of the configured group is not an admin once the claim replaces it');
        self::assertTrue(
            $log->hasRecord(
                sprintf(
                    "System Admin group 'Starter Admins' was chosen by %s; the label in config/authorization.php was not used.",
                    ClaimingExtension::class,
                ),
                Level::Notice,
            ),
            'the takeover names the label, the extension, and the unused config label',
        );
    }

    public function testAClaimIsLoggedWhenTheConfigHadNoLabel(): void
    {
        $this->writeAuthorizationConfig('new Guild\Framework\Authorization\AuthorizationConfiguration()');
        $log = new TestHandler();

        $this->bootApp(new ClaimingExtension(directory: new ArrayGroupDirectory(), systemAdminGroup: 'Starter Admins'), new Logger('test', [$log]));

        self::assertTrue(
            $log->hasRecord(
                sprintf("System Admin group 'Starter Admins' was chosen by %s.", ClaimingExtension::class),
                Level::Notice,
            ),
            'every honoured claim is logged, even when no config label was overridden',
        );
    }

    public function testWithoutAClaimABlankLabelFailsAtBoot(): void
    {
        $this->writeAuthorizationConfig('new Guild\Framework\Authorization\AuthorizationConfiguration()');
        $builder = Application::configure($this->basePath, [new ClaimingExtension(directory: new ArrayGroupDirectory())])->addIlluminateDatabase();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'config/authorization.php gives no System Admin group label. Pass systemAdminGroup: the ACM label of the group '
            . 'whose members administer this application (starter reads it from AUTHORIZATION_SYSTEM_ADMIN_GROUP). '
            . 'An installed extension can supply it instead (guild/dev-tools reads it from systemAdminGroup: in '
            . 'config/personas.php).'
        );

        $builder->addAuthorization(IdentitySource::Cas, TestPermission::class);
    }

    public function testTheBuilderTrimsALabelItIsGiven(): void
    {
        $this->writeAuthorizationConfig('new Guild\Framework\Authorization\AuthorizationConfiguration()');

        self::assertTrue(
            $this->isSystemAdmin(new ClaimingExtension(
                directory: $this->directoryWith('Starter Admins'),
                systemAdminGroup: ' Starter Admins ',
            )),
            "useSystemAdminGroup(' Starter Admins ') makes members of 'Starter Admins' System Admins",
        );
    }

    public function testALabelChosenAfterAddAuthorizationIsRejected(): void
    {
        $this->writeAuthorizationConfig("new Guild\\Framework\\Authorization\\AuthorizationConfiguration('My App Admins')");
        $builder = Application::configure($this->basePath, [new ClaimingExtension(directory: new ArrayGroupDirectory())])
            ->withLogger(new NullLogger())
            ->addIlluminateDatabase()
            ->addAuthorization(IdentitySource::Cas, TestPermission::class);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'The System Admin group was chosen by the application after addAuthorization() had already run without it.'
        );

        $builder->useSystemAdminGroup('Starter Admins');
    }

    private function writeAuthorizationConfig(string $expression): void
    {
        file_put_contents($this->basePath . '/config/authorization.php', "<?php\n\nreturn {$expression};\n");
    }

    private function directoryWith(string $label): ArrayGroupDirectory
    {
        return new ArrayGroupDirectory(['jdoe' => [new GrouperGroup('iu:x-admins', 'Indiana University:X:Admins', $label, 'uuid-1')]]);
    }

    private function bootApp(ClaimingExtension $extension, Logger|NullLogger $logger = new NullLogger()): Application
    {
        $app = Application::configure($this->basePath, [$extension])
            ->withLogger($logger)
            ->addIlluminateDatabase()
            ->addAuthorization(IdentitySource::Cas, TestPermission::class)
            ->create();
        $app->addShared(SessionStore::class, new ArraySessionStore(), overwrite: true);
        $app->addShared(
            ServerRequestInterface::class,
            (new ServerRequestFactory())->createServerRequest('GET', '/', ['REMOTE_USER' => 'jdoe']),
            overwrite: true,
        );

        return $app;
    }

    private function isSystemAdmin(ClaimingExtension $extension): bool
    {
        return $this->resolveAdmin($this->bootApp($extension));
    }

    private function resolveAdmin(Application $app): bool
    {
        $resolver = $app->get(UserResolver::class);
        self::assertInstanceOf(UserResolver::class, $resolver);
        $user = $resolver->current();
        self::assertInstanceOf(User::class, $user, 'jdoe is signed in');

        return $user->isSystemAdmin();
    }
}
