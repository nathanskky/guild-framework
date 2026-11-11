<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Extension;

use Guild\Framework\Application;
use Guild\Framework\ApplicationBuilder;
use Guild\Framework\Authorization\IdentitySource;
use Guild\Framework\Authorization\Membership\GroupDirectory;
use Guild\Framework\Authorization\Membership\MembershipCache;
use Guild\Framework\Authorization\Membership\MembershipProvider;
use Guild\Framework\Exception\ConfigurationException;
use Guild\Framework\Extension\ClaimedSlots;
use Guild\Framework\Extension\SlotClaim;
use Guild\Framework\ServiceProvider\AuthorizationServiceProvider;
use Guild\Framework\Session\SessionStore;
use Guild\Framework\Test\Authorization\Support\TestPermission;
use Guild\Framework\Test\Extension\Support\ArrayGroupDirectory;
use Guild\Framework\Test\Extension\Support\ClaimingExtension;
use Guild\Framework\Testing\ArraySessionStore;
use Guild\Grouper\GrouperClient;
use Guild\Grouper\GrouperGroup;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(ApplicationBuilder::class)]
#[CoversClass(AuthorizationServiceProvider::class)]
#[UsesClass(Application::class)]
#[UsesClass(ClaimedSlots::class)]
#[UsesClass(SlotClaim::class)]
#[UsesClass(ClaimingExtension::class)]
#[UsesClass(ArrayGroupDirectory::class)]
#[UsesClass(MembershipProvider::class)]
#[UsesClass(MembershipCache::class)]
#[UsesClass(ArraySessionStore::class)]
final class GroupDirectorySlotTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/guild-framework-group-directory-slot-' . bin2hex(random_bytes(6));
        mkdir($this->basePath . '/config', recursive: true);
        file_put_contents($this->basePath . '/config/authorization.php', <<<'PHP'
            <?php

            return new Guild\Framework\Authorization\AuthorizationConfiguration('My App Admins');
            PHP);
    }

    protected function tearDown(): void
    {
        unlink($this->basePath . '/config/authorization.php');
        rmdir($this->basePath . '/config');
        rmdir($this->basePath);
    }

    public function testAClaimedDirectoryIsUsedWithoutGrouperConfig(): void
    {
        $editors = new GrouperGroup('iu:x-editors', 'Indiana University:X:Editors', 'X Editors', 'uuid-1');
        $directory = new ArrayGroupDirectory(['jdoe' => [$editors]]);

        $app = Application::configure($this->basePath, [new ClaimingExtension(directory: $directory)])
            ->withLogger(new NullLogger())
            ->addAuthorization(IdentitySource::Cas, TestPermission::class)
            ->create();
        $app->addShared(SessionStore::class, new ArraySessionStore(), overwrite: true);

        $provider = $app->get(MembershipProvider::class);
        self::assertInstanceOf(MembershipProvider::class, $provider);
        $membership = $provider->membershipFor('jdoe');

        self::assertSame($directory, $app->get(GroupDirectory::class), 'GroupDirectory resolves to the extension\'s instance');
        self::assertSame([$editors], $membership->groups, 'membership is the claimed directory\'s answer');
        self::assertFalse($app->has(GrouperClient::class), 'no Grouper client is bound');

        $cache = $app->get(MembershipCache::class);
        self::assertInstanceOf(MembershipCache::class, $cache);
        self::assertEquals([$editors], $cache->get('jdoe')['groups'] ?? null, 'and the answer went through the real cache');
    }

    public function testTheTakeoverIsLogged(): void
    {
        $log = new TestHandler();

        Application::configure($this->basePath, [new ClaimingExtension(directory: new ArrayGroupDirectory())])
            ->withLogger(new Logger('test', [$log]))
            ->addAuthorization(IdentitySource::Cas, TestPermission::class)
            ->create();

        self::assertTrue(
            $log->hasRecord(
                sprintf(
                    'Group directory %s was chosen by %s; config/grouper.php was not read.',
                    ArrayGroupDirectory::class,
                    ClaimingExtension::class,
                ),
                Level::Notice,
            ),
            'the takeover names the group directory and the extension that chose it',
        );
    }

    public function testWithoutAClaimAMissingGrouperConfigStillFails(): void
    {
        $builder = Application::configure($this->basePath, [new ClaimingExtension()]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'config/grouper.php was not found. addAuthorization() reads the Grouper connection settings from it: '
            . 'move the GrouperConfiguration out of config/authorization.php into config/grouper.php, '
            . 'which must return a GrouperDirectoryConfiguration.'
        );

        $builder->addAuthorization(IdentitySource::Cas, TestPermission::class);
    }
}
