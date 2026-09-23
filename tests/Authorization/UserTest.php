<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authorization;

use Guild\Framework\Authorization\GrantRepository;
use Guild\Framework\Authorization\Identity\Identity;
use Guild\Framework\Authorization\Membership\Membership;
use Guild\Framework\Authorization\Membership\MembershipCache;
use Guild\Framework\Authorization\Membership\MembershipProvider;
use Guild\Framework\Authorization\Membership\Session;
use Guild\Framework\Authorization\User;
use Guild\Framework\Authorization\UserGroup;
use Guild\Framework\Exception\AuthorizationUnavailableException;
use Guild\Framework\Exception\ConfigurationException;
use Guild\Framework\Test\Authorization\Support\FakeGrouper;
use Guild\Framework\Test\Authorization\Support\GrantsDatabase;
use Guild\Framework\Test\Authorization\Support\OtherPermission;
use Guild\Framework\Test\Authorization\Support\TestPermission;
use Guild\Grouper\GrouperGroup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Membership is cached in the PHP session, so each test runs in its own process.
 */
#[CoversClass(User::class)]
#[CoversClass(UserGroup::class)]
#[UsesClass(Identity::class)]
#[UsesClass(GrantRepository::class)]
#[UsesClass(Membership::class)]
#[UsesClass(MembershipCache::class)]
#[UsesClass(MembershipProvider::class)]
#[UsesClass(Session::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class UserTest extends TestCase
{
    private const int NOW = 100_000;

    private const int TTL = 900;

    private GrantsDatabase $db;

    protected function setUp(): void
    {
        $this->db = new GrantsDatabase();
    }

    public function testIdentityIsAvailableWithoutLoadingAnything(): void
    {
        $grouper = new FakeGrouper([]);
        $user = $this->user($grouper, attributes: ['email' => 'jdoe@iu.edu']);

        self::assertSame('jdoe', $user->username(), 'the username comes from the identity');
        self::assertSame('jdoe@iu.edu', $user->attribute('email'), 'a claim is readable');
        self::assertNull($user->attribute('missing'), 'an absent claim is null');
        self::assertSame(0, $grouper->requestCount(), 'nothing touches Grouper until membership is needed');
    }

    public function testGroupsCarryTheIdentifierAndTheAcmLabel(): void
    {
        $user = $this->user(new FakeGrouper([FakeGrouper::membership(['iu:roles:sys:acm:x-editors' => 'X Editors'])]));

        self::assertEquals([new UserGroup('iu:roles:sys:acm:x-editors', 'X Editors')], $user->groups(), 'label is the displayExtension');
        self::assertTrue($user->inGroup('iu:roles:sys:acm:x-editors'), 'membership matches on the identifier');
        self::assertFalse($user->inGroup('X Editors'), 'the label is never matched');
    }

    public function testRolesAndPermissionsComeFromTheMappedGroups(): void
    {
        $this->db->map($this->db->group('iu:x-editors'), $this->db->role('Editor', ['documents.create', 'documents.update']));
        $user = $this->user(new FakeGrouper([FakeGrouper::membership(['iu:x-editors' => 'X Editors'])]));

        self::assertSame(['Editor'], $user->roles(), 'the mapped role');
        self::assertSame([TestPermission::DocumentsCreate, TestPermission::DocumentsUpdate], $user->permissions(), 'as enum cases');
        self::assertTrue($user->hasPermission(TestPermission::DocumentsUpdate), 'a granted permission');
        self::assertFalse($user->hasPermission(TestPermission::InvoicesApprove), 'an ungranted permission');
    }

    public function testAGrantNoLongerInTheEnumIsDropped(): void
    {
        $this->db->map($this->db->group('iu:x-editors'), $this->db->role('Editor', ['documents.create', 'documents.retired']));
        $user = $this->user(new FakeGrouper([FakeGrouper::membership(['iu:x-editors' => 'X Editors'])]));

        self::assertSame([TestPermission::DocumentsCreate], $user->permissions(), 'an orphaned grant grants nothing');
    }

    public function testACaseOfAnotherEnumThrows(): void
    {
        $user = $this->user(new FakeGrouper([FakeGrouper::membership([])]));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('not a case of the registered permission enum');

        $user->hasPermission(OtherPermission::DocumentsCreate);
    }

    public function testRepeatedChecksLoadOnce(): void
    {
        $this->db->map($this->db->group('iu:x-editors'), $this->db->role('Editor', ['documents.create']));
        $grouper = new FakeGrouper([FakeGrouper::membership(['iu:x-editors' => 'X Editors'])]);
        $user = $this->user($grouper);
        $this->db->connection->enableQueryLog();

        $user->hasPermission(TestPermission::DocumentsCreate);
        $user->hasPermission(TestPermission::DocumentsUpdate);
        $user->roles();
        $user->groups();
        $user->isSystemAdmin();

        self::assertSame(1, $grouper->requestCount(), 'Grouper is asked once per request');
        self::assertCount(1, $this->db->connection->getQueryLog(), 'grants are queried once per request');
    }

    public function testAnUnavailableAnswerIsRememberedForTheRequest(): void
    {
        $grouper = new FakeGrouper([FakeGrouper::unavailable(), FakeGrouper::membership([])]);
        $user = $this->user($grouper);

        foreach ([1, 2] as $attempt) {
            try {
                $user->hasPermission(TestPermission::DocumentsCreate);
                self::fail("attempt {$attempt}: expected AuthorizationUnavailableException");
            } catch (AuthorizationUnavailableException) {
            }
        }

        self::assertSame(1, $grouper->requestCount(), 'an outage costs one Grouper call per request, not one per check');
    }

    public function testStaleMembershipStillAnswersOrdinaryChecks(): void
    {
        $this->db->map($this->db->group('iu:x-editors'), $this->db->role('Editor', ['documents.create']));
        $this->cache()->put('jdoe', [$this->grouperGroup('iu:x-editors', 'X Editors')], self::NOW - self::TTL - 1);
        $user = $this->user(new FakeGrouper([FakeGrouper::unavailable()]));

        self::assertTrue($user->hasPermission(TestPermission::DocumentsCreate), 'stale membership within the cap is used');
    }

    public function testSystemAdminIsExactlyOneGroupWithTheConfiguredLabel(): void
    {
        $admin = $this->user(new FakeGrouper([FakeGrouper::membership(['iu:x-admins' => 'My App Admins'])]));
        $nonAdmin = $this->user(new FakeGrouper([FakeGrouper::membership(['iu:x-editors' => 'X Editors'])]), username: 'asmith');

        self::assertTrue($admin->isSystemAdmin(), 'one matching group grants');
        self::assertFalse($nonAdmin->isSystemAdmin(), 'no matching group denies');
    }

    public function testAnAmbiguousSystemAdminLabelThrows(): void
    {
        $user = $this->user(new FakeGrouper([FakeGrouper::membership([
            'iu:x-admins' => 'My App Admins',
            'iu:y-admins' => 'My App Admins',
        ])]));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('iu:x-admins, iu:y-admins');

        $user->isSystemAdmin();
    }

    public function testSystemAdminNeverUsesStaleMembership(): void
    {
        $this->cache()->put('jdoe', [$this->grouperGroup('iu:x-admins', 'My App Admins')], self::NOW - self::TTL - 1);
        $user = $this->user(new FakeGrouper([FakeGrouper::unavailable()]));

        $this->expectException(AuthorizationUnavailableException::class);

        $user->isSystemAdmin();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function user(FakeGrouper $grouper, string $username = 'jdoe', array $attributes = []): User
    {
        return new User(
            new Identity($username, $attributes),
            new MembershipProvider($grouper->client, $this->cache(), new NullLogger(), self::TTL, 3600, static fn (): int => self::NOW),
            new GrantRepository($this->db->connection),
            TestPermission::class,
            'My App Admins',
        );
    }

    private function cache(): MembershipCache
    {
        return new MembershipCache(new Session());
    }

    private function grouperGroup(string $identifier, string $label): GrouperGroup
    {
        return new GrouperGroup($identifier, 'IU:' . $label, $label, 'uuid');
    }
}
