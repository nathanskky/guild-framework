<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Extension;

use Closure;
use Guild\Framework\Application;
use Guild\Framework\ApplicationBuilder;
use Guild\Framework\Exception\ConfigurationException;
use Guild\Framework\Extension\ClaimedSlots;
use Guild\Framework\Extension\SlotClaim;
use Guild\Framework\ServiceProvider\FrameworkPageServiceProvider;
use Guild\Framework\Test\Authentication\Support\FakeSignIn;
use Guild\Framework\Test\Extension\Support\RecordingExtension;
use Guild\Framework\Test\Extension\Support\UnusedGroupDirectory;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\NullLogger;
use RuntimeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(ClaimedSlots::class)]
#[CoversClass(SlotClaim::class)]
#[CoversClass(Application::class)]
#[CoversClass(ApplicationBuilder::class)]
#[UsesClass(FrameworkPageServiceProvider::class)]
#[UsesClass(RecordingExtension::class)]
#[UsesClass(FakeSignIn::class)]
#[UsesClass(UnusedGroupDirectory::class)]
final class ClaimedSlotsTest extends TestCase
{
    protected function setUp(): void
    {
        RecordingExtension::$calls = [];
    }

    public function testConfigureRunsEachExtensionBeforeTheApplicationsCalls(): void
    {
        Application::configure(sys_get_temp_dir(), [new RecordingExtension()]);

        self::assertSame(
            ['configure'],
            RecordingExtension::$calls,
            'the extension is configured by the time Application::configure() hands back the builder, and not booted',
        );
    }

    public function testCreateBootsEachExtensionOnce(): void
    {
        $builder = Application::configure(sys_get_temp_dir(), [new RecordingExtension()]);

        $builder->create();
        $builder->create();

        self::assertSame(['configure', 'boot'], RecordingExtension::$calls, 'a second create() boots nothing again');
    }

    public function testAClaimRecordsTheExtensionThatMadeIt(): void
    {
        $extension = new RecordingExtension(static function (ApplicationBuilder $builder): void {
            $builder->useSignInMethod(FakeSignIn::class)->useSystemAdminGroup('Starter Admins');
        });

        $app = Application::configure(sys_get_temp_dir(), [$extension])
            ->withLogger(new NullLogger())
            ->useGroupDirectory(UnusedGroupDirectory::class)
            ->create();

        $slots = $app->claimedSlots;
        self::assertEquals(
            new SlotClaim(FakeSignIn::class, RecordingExtension::class),
            $slots->signInMethod,
            'the sign-in method names the class and the extension that chose it',
        );
        self::assertEquals(
            new SlotClaim('Starter Admins', RecordingExtension::class),
            $slots->systemAdminGroup,
            'the System Admin group names the label and the extension that chose it',
        );
        self::assertEquals(
            new SlotClaim(UnusedGroupDirectory::class, 'the application'),
            $slots->groupDirectory,
            'a claim made outside any extension is the application\'s',
        );
    }

    /**
     * @param  Closure(ClaimedSlots): void  $claim
     */
    #[DataProvider('slots')]
    public function testTheSameSlotClaimedTwiceIsRejectedNamingBoth(string $slot, Closure $claim): void
    {
        $slots = new ClaimedSlots();
        $slots->during('Acme\FirstExtension', static fn () => $claim($slots));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            $slot . ' was already chosen by Acme\FirstExtension; Acme\SecondExtension cannot choose it too. '
            . 'Remove one of the two packages.'
        );

        $slots->during('Acme\SecondExtension', static fn () => $claim($slots));
    }

    /**
     * @return iterable<string, array{string, Closure(ClaimedSlots): void}>
     */
    public static function slots(): iterable
    {
        yield 'sign-in method' => [
            'The sign-in method',
            static fn (ClaimedSlots $slots) => $slots->claimSignInMethod(FakeSignIn::class),
        ];
        yield 'group directory' => [
            'The group directory',
            static fn (ClaimedSlots $slots) => $slots->claimGroupDirectory(UnusedGroupDirectory::class),
        ];
        yield 'System Admin group' => [
            'The System Admin group',
            static fn (ClaimedSlots $slots) => $slots->claimSystemAdminGroup('Starter Admins'),
        ];
    }

    public function testTheClaimantIsRestoredWhenAnExtensionFails(): void
    {
        $slots = new ClaimedSlots();

        try {
            $slots->during('Acme\FailingExtension', static function (): never {
                throw new RuntimeException('configure failed');
            });
        } catch (RuntimeException) {
        }

        $slots->claimSignInMethod(FakeSignIn::class);

        self::assertSame('the application', $slots->signInMethod?->claimant, 'the failed extension is no longer the claimant');
    }

    /**
     * @param  Closure(ApplicationBuilder): void  $choose
     */
    #[DataProvider('wrongTypes')]
    public function testAClassOfTheWrongTypeIsRejected(Closure $choose, string $message): void
    {
        $builder = Application::configure(sys_get_temp_dir(), []);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($message);

        $choose($builder);
    }

    /**
     * @return iterable<string, array{Closure(ApplicationBuilder): void, string}>
     */
    public static function wrongTypes(): iterable
    {
        yield 'sign-in method' => [
            static fn (ApplicationBuilder $builder) => $builder->useSignInMethod(stdClass::class),
            'stdClass cannot be the sign-in method: it does not implement SignInMethod.',
        ];
        yield 'group directory' => [
            static fn (ApplicationBuilder $builder) => $builder->useGroupDirectory(FakeSignIn::class),
            FakeSignIn::class . ' cannot be the group directory: it does not implement GroupDirectory.',
        ];
    }

    public function testABlankLabelIsRejected(): void
    {
        $builder = Application::configure(sys_get_temp_dir(), []);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('The System Admin group label must not be blank.');

        $builder->useSystemAdminGroup('  ');
    }

    public function testTakeoverNoticesAreLoggedAtCreate(): void
    {
        $app = new Application(sys_get_temp_dir());
        $builder = new ApplicationBuilder($app);
        $app->claimedSlots->takeover('Acme\Extension chose the sign-in method.');

        $log = new TestHandler();
        $builder->withLogger(new Logger('test', [$log]));
        $builder->create();
        $builder->create();

        self::assertCount(1, $log->getRecords(), 'one notice, even though create() ran twice');
        self::assertTrue(
            $log->hasRecord('Acme\Extension chose the sign-in method.', Level::Notice),
            'the takeover is logged at notice',
        );
    }

    public function testAClaimAfterItsSlotWasReadIsRejected(): void
    {
        $slots = new ClaimedSlots();
        $slots->markSignInMethodRead('addAuthentication()');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'The sign-in method was chosen by Acme\LateExtension after addAuthentication() had already run without it. '
            . "Choose it before that call; an extension's configure() runs before the application's own calls."
        );

        $slots->during('Acme\LateExtension', static fn () => $slots->claimSignInMethod(FakeSignIn::class));
    }

    public function testAnUnusedClaimIsLoggedAtCreate(): void
    {
        $extension = new RecordingExtension(static function (ApplicationBuilder $builder): void {
            $builder->useGroupDirectory(UnusedGroupDirectory::class);
        });
        $log = new TestHandler();

        Application::configure(sys_get_temp_dir(), [$extension])
            ->withLogger(new Logger('test', [$log]))
            ->create();

        self::assertTrue(
            $log->hasRecord(
                'Group directory ' . UnusedGroupDirectory::class . ' was chosen by ' . RecordingExtension::class
                . ', but addAuthorization() was not called, so it is not used.',
                Level::Notice,
            ),
            'an application that never adds authorization still boots, and says the choice went unused',
        );
    }

    public function testAClaimedClassTheContainerCannotBuildFailsAtCreate(): void
    {
        $extension = new RecordingExtension(static function (ApplicationBuilder $builder): void {
            $builder->useSignInMethod(FakeSignIn::class);
        });
        $builder = Application::configure(sys_get_temp_dir(), [$extension])
            ->withLogger(new NullLogger())
            ->addAuthentication();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Sign-in method ' . FakeSignIn::class . ' was chosen by ' . RecordingExtension::class
            . ', but the container cannot build it. Bind it in ' . RecordingExtension::class
            . '::boot(), or call enableAutoWiring().'
        );

        $builder->create();
    }

    public function testTheBuilderGivesExtensionsTheConfigPath(): void
    {
        $builder = Application::configure('/srv/app', []);

        self::assertSame('/srv/app/config', $builder->path('config'), 'an extension finds its own config file beside the application\'s');
    }
}
