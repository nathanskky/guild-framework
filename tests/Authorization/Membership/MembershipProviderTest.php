<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authorization\Membership;

use Guild\Framework\Authorization\Membership\Membership;
use Guild\Framework\Authorization\Membership\MembershipCache;
use Guild\Framework\Authorization\Membership\MembershipProvider;
use Guild\Framework\Authorization\Membership\Session;
use Guild\Framework\Exception\AuthorizationUnavailableException;
use Guild\Framework\Test\Authorization\Support\FakeGrouper;
use Guild\Grouper\Exception\GrouperConfigurationException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * TTL 900 s and stale cap 3600 s throughout, with the clock under test control.
 */
#[CoversClass(MembershipProvider::class)]
#[UsesClass(Membership::class)]
#[UsesClass(MembershipCache::class)]
#[UsesClass(Session::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MembershipProviderTest extends TestCase
{
    private const int TTL = 900;

    private const int STALE_CAP = 3600;

    private int $now = 100_000;

    private TestHandler $log;

    private MembershipCache $cache;

    protected function setUp(): void
    {
        $this->log = new TestHandler();
        $this->cache = new MembershipCache(new Session());
    }

    public function testAMissAsksGrouperAndCachesTheAnswer(): void
    {
        $grouper = new FakeGrouper([FakeGrouper::membership(['iu:x' => 'X'])]);

        $membership = $this->provider($grouper)->membershipFor('jdoe');

        self::assertTrue($membership->fresh, 'a Grouper answer is fresh');
        self::assertSame('iu:x', $membership->groups[0]->identifier, 'the groups are Grouper\'s');
        self::assertSame($this->now, $this->cache->get('jdoe')['fetchedAt'] ?? null, 'the answer is cached');
    }

    public function testAnEntryWithinTheTtlIsUsedWithoutAskingGrouper(): void
    {
        $this->cache->put('jdoe', [], $this->now - self::TTL + 1);
        $grouper = new FakeGrouper([]);

        $membership = $this->provider($grouper)->membershipFor('jdoe');

        self::assertTrue($membership->fresh, 'an entry within the TTL is fresh');
        self::assertSame(0, $grouper->requestCount(), 'Grouper is not asked');
    }

    public function testAnEntryPastTheTtlIsRefreshed(): void
    {
        $this->cache->put('jdoe', [], $this->now - self::TTL);
        $grouper = new FakeGrouper([FakeGrouper::membership(['iu:x' => 'X'])]);

        $membership = $this->provider($grouper)->membershipFor('jdoe');

        self::assertSame(1, $grouper->requestCount(), 'Grouper is asked once the TTL has passed');
        self::assertCount(1, $membership->groups, 'the new answer is used');
    }

    public function testAnotherUsersEntryIsNotUsed(): void
    {
        $this->cache->put('asmith', [], $this->now);
        $grouper = new FakeGrouper([FakeGrouper::membership([])]);

        $this->provider($grouper)->membershipFor('jdoe');

        self::assertSame(1, $grouper->requestCount(), 'a different user\'s entry is a miss');
    }

    public function testAnEmptyMembershipIsASuccess(): void
    {
        $grouper = new FakeGrouper([FakeGrouper::membership([])]);

        $membership = $this->provider($grouper)->membershipFor('jdoe');

        self::assertSame([], $membership->groups, 'no groups is a valid answer');
        self::assertNotNull($this->cache->get('jdoe'), 'and it is cached like any other');
    }

    public function testUnavailableGrouperServesStaleMembershipWithinTheCapAndWarns(): void
    {
        $this->cache->put('jdoe', [], $this->now - self::TTL - self::STALE_CAP + 1);
        $grouper = new FakeGrouper([FakeGrouper::unavailable()]);

        $membership = $this->provider($grouper)->membershipFor('jdoe');

        self::assertFalse($membership->fresh, 'cached membership past the TTL is stale');
        self::assertTrue(
            $this->log->hasWarningThatContains('serving cached group membership for jdoe that is 4499 seconds old'),
            'serving stale membership is logged every time',
        );
    }

    public function testUnavailableGrouperPastTheCapThrows(): void
    {
        $this->cache->put('jdoe', [], $this->now - self::TTL - self::STALE_CAP);
        $grouper = new FakeGrouper([FakeGrouper::unavailable()]);

        try {
            $this->provider($grouper)->membershipFor('jdoe');
            self::fail('expected AuthorizationUnavailableException');
        } catch (AuthorizationUnavailableException $exception) {
            self::assertStringContainsString('jdoe', $exception->getMessage(), 'the message names the user');
        }

        self::assertTrue($this->log->hasErrorThatContains('no usable cached group membership'), 'the failure is logged');
    }

    public function testUnavailableGrouperWithNoEntryThrows(): void
    {
        $this->expectException(AuthorizationUnavailableException::class);

        $this->provider(new FakeGrouper([FakeGrouper::unavailable()]))->membershipFor('jdoe');
    }

    public function testAZeroStaleCapNeverServesStaleMembership(): void
    {
        $this->cache->put('jdoe', [], $this->now - self::TTL);

        $this->expectException(AuthorizationUnavailableException::class);

        $this->provider(new FakeGrouper([FakeGrouper::unavailable()]), staleCap: 0)->membershipFor('jdoe');
    }

    public function testRejectedCredentialsPropagate(): void
    {
        $this->cache->put('jdoe', [], $this->now - self::TTL);

        $this->expectException(GrouperConfigurationException::class);

        $this->provider(new FakeGrouper([FakeGrouper::rejectedCredentials()]))->membershipFor('jdoe');
    }

    private function provider(FakeGrouper $grouper, int $staleCap = self::STALE_CAP): MembershipProvider
    {
        return new MembershipProvider(
            $grouper->client,
            $this->cache,
            new Logger('test', [$this->log], [new PsrLogMessageProcessor()]),
            self::TTL,
            $staleCap,
            fn (): int => $this->now,
        );
    }
}
