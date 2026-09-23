<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authorization;

use Guild\Framework\Authorization\Gate;
use Guild\Framework\Authorization\GrantRepository;
use Guild\Framework\Authorization\Handles;
use Guild\Framework\Authorization\HandlesResource;
use Guild\Framework\Authorization\Identity\Identity;
use Guild\Framework\Authorization\Identity\IdentityReader;
use Guild\Framework\Authorization\Membership\Membership;
use Guild\Framework\Authorization\Membership\MembershipCache;
use Guild\Framework\Authorization\Membership\MembershipProvider;
use Guild\Framework\Authorization\Membership\Session;
use Guild\Framework\Authorization\Policy\PolicyMethod;
use Guild\Framework\Authorization\Policy\PolicyRegistry;
use Guild\Framework\Authorization\User;
use Guild\Framework\Authorization\UserResolver;
use Guild\Framework\Exception\AuthenticationRequiredException;
use Guild\Framework\Exception\AuthorizationException;
use Guild\Framework\Exception\AuthorizationUnavailableException;
use Guild\Framework\Exception\ConfigurationException;
use Guild\Framework\Test\Authorization\Support\FakeGrouper;
use Guild\Framework\Test\Authorization\Support\GrantsDatabase;
use Guild\Framework\Test\Authorization\Support\OtherPermission;
use Guild\Framework\Test\Authorization\Support\Policy\Document;
use Guild\Framework\Test\Authorization\Support\Policy\DocumentPolicy;
use Guild\Framework\Test\Authorization\Support\TestPermission;
use Guild\Grouper\GrouperGroup;
use League\Container\Container;
use League\Container\ReflectionContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * jdoe is in iu:x-editors, whose role grants documents.update. iu:x-admins
 * carries the System Admin label. Membership is cached in the session, so
 * each test runs in its own process.
 */
#[CoversClass(Gate::class)]
#[UsesClass(PolicyRegistry::class)]
#[UsesClass(PolicyMethod::class)]
#[UsesClass(Handles::class)]
#[UsesClass(HandlesResource::class)]
#[UsesClass(User::class)]
#[UsesClass(UserResolver::class)]
#[UsesClass(Identity::class)]
#[UsesClass(GrantRepository::class)]
#[UsesClass(Membership::class)]
#[UsesClass(MembershipCache::class)]
#[UsesClass(MembershipProvider::class)]
#[UsesClass(Session::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GateTest extends TestCase
{
    private const int NOW = 100_000;

    private const int TTL = 900;

    private GrantsDatabase $db;

    protected function setUp(): void
    {
        $this->db = new GrantsDatabase();
        $this->db->map($this->db->group('iu:x-editors'), $this->db->role('Editor', ['documents.update']));
        DocumentPolicy::$calls = 0;
    }

    public function testWithoutAResourceTheRolesDecide(): void
    {
        $gate = $this->gate(['iu:x-editors' => 'X Editors']);

        self::assertTrue($gate->allows(TestPermission::DocumentsUpdate), 'a granted permission');
        self::assertTrue($gate->denies(TestPermission::DocumentsCreate), 'an ungranted permission');
    }

    public function testWithAResourceTheGrantAndThePolicyMustBothAgree(): void
    {
        $gate = $this->gate(['iu:x-editors' => 'X Editors']);

        self::assertTrue($gate->allows(TestPermission::DocumentsUpdate, new Document('jdoe')), 'granted and owned');
        self::assertFalse($gate->allows(TestPermission::DocumentsUpdate, new Document('asmith')), 'granted but not owned');
    }

    public function testThePolicyIsNotAskedWhenTheGrantIsMissing(): void
    {
        $gate = $this->gate([]);

        self::assertFalse($gate->allows(TestPermission::DocumentsUpdate, new Document('jdoe')), 'owned but not granted');
        self::assertSame(0, DocumentPolicy::$calls, 'the policy decides instances, not the capability');
    }

    public function testAGuestIsDeniedWithoutAResource(): void
    {
        self::assertFalse($this->guestGate()->allows(TestPermission::DocumentsUpdate), 'guests hold no grants');
    }

    public function testAGuestReachesOnlyAPolicyMethodThatAcceptsNull(): void
    {
        $gate = $this->guestGate();

        self::assertTrue($gate->allows(TestPermission::DocumentsView, new Document('jdoe', public: true)), '?User opts in');
        self::assertFalse($gate->allows(TestPermission::DocumentsView, new Document('jdoe')), 'and still decides');
        self::assertFalse($gate->allows(TestPermission::DocumentsUpdate, new Document('jdoe')), 'User excludes guests');
        self::assertSame(2, DocumentPolicy::$calls, 'the non-nullable method was never called');
    }

    public function testASystemAdminIsAllowedEverything(): void
    {
        $gate = $this->gate(['iu:x-admins' => 'My App Admins']);

        self::assertTrue($gate->allows(TestPermission::InvoicesApprove), 'without any grant');
        self::assertTrue($gate->allows(TestPermission::DocumentsUpdate, new Document('asmith')), 'on anyone\'s resource');
    }

    public function testTheBypassDoesNotApplyOnStaleMembershipButOrdinaryRulesStillDo(): void
    {
        $this->cache()->put('jdoe', [
            new GrouperGroup('iu:x-admins', 'IU:My App Admins', 'My App Admins', 'uuid-a'),
            new GrouperGroup('iu:x-editors', 'IU:X Editors', 'X Editors', 'uuid-e'),
        ], self::NOW - self::TTL - 1);
        $gate = $this->gateOver(new FakeGrouper([FakeGrouper::unavailable()]), new Identity('jdoe'));

        self::assertFalse($gate->allows(TestPermission::InvoicesApprove), 'no bypass on stale membership');
        self::assertTrue($gate->allows(TestPermission::DocumentsUpdate), 'the grant still answers from stale membership');
    }

    public function testUnavailableMembershipIsNeverADenial(): void
    {
        $gate = $this->gateOver(new FakeGrouper([FakeGrouper::unavailable()]), new Identity('jdoe'));

        $this->expectException(AuthorizationUnavailableException::class);

        $gate->allows(TestPermission::DocumentsUpdate);
    }

    public function testAuthorizeDistinguishesAGuestFromADeniedUser(): void
    {
        try {
            $this->gate([])->authorize(TestPermission::DocumentsUpdate);
            self::fail('expected AuthorizationException');
        } catch (AuthorizationException $exception) {
            self::assertNotInstanceOf(AuthenticationRequiredException::class, $exception, 'a signed-in user is refused, not asked to log in');
        }

        $this->expectException(AuthenticationRequiredException::class);

        $this->guestGate()->authorize(TestPermission::DocumentsUpdate);
    }

    public function testAuthorizePassesSilently(): void
    {
        $this->gate(['iu:x-editors' => 'X Editors'])->authorize(TestPermission::DocumentsUpdate, new Document('jdoe'));

        $this->addToAssertionCount(1);
    }

    public function testAPolicyMistakeThrowsEvenForASystemAdmin(): void
    {
        $gate = $this->gate(['iu:x-admins' => 'My App Admins']);

        $this->expectException(ConfigurationException::class);

        $gate->allows(TestPermission::DocumentsCreate, new Document('jdoe'));
    }

    public function testACaseOfAnotherEnumThrowsEvenForAGuest(): void
    {
        $this->expectException(ConfigurationException::class);

        $this->guestGate()->allows(OtherPermission::DocumentsCreate);
    }

    /**
     * @param  array<string, string>  $groups  identifier => displayExtension
     */
    private function gate(array $groups): Gate
    {
        return $this->gateOver(new FakeGrouper([FakeGrouper::membership($groups)]), new Identity('jdoe'));
    }

    private function guestGate(): Gate
    {
        return $this->gateOver(new FakeGrouper([]), null);
    }

    private function gateOver(FakeGrouper $grouper, ?Identity $identity): Gate
    {
        $reader = new class ($identity) implements IdentityReader {
            public function __construct(private readonly ?Identity $identity)
            {
            }

            public function read(): ?Identity
            {
                return $this->identity;
            }
        };

        $container = new Container();
        $container->delegate(new ReflectionContainer());

        return new Gate(
            new UserResolver(
                $reader,
                new MembershipProvider($grouper->client, $this->cache(), new NullLogger(), self::TTL, 3600, static fn (): int => self::NOW),
                new GrantRepository($this->db->connection),
                TestPermission::class,
                'My App Admins',
            ),
            new PolicyRegistry($container, [DocumentPolicy::class], TestPermission::class),
            TestPermission::class,
        );
    }

    private function cache(): MembershipCache
    {
        return new MembershipCache(new Session());
    }
}
