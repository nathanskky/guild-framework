<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authorization\Membership;

use Guild\Framework\Authorization\Membership\MembershipCache;
use Guild\Framework\Authorization\Membership\Session;
use Guild\Grouper\GrouperGroup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[CoversClass(MembershipCache::class)]
#[CoversClass(Session::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MembershipCacheTest extends TestCase
{
    public function testAStoredMembershipReadsBack(): void
    {
        $cache = new MembershipCache(new Session());
        $group = new GrouperGroup('iu:roles:sys:acm:x-editors', 'IU:Roles:X Editors', 'X Editors', 'uuid-1', 'Editors of X');

        $cache->put('jdoe', [$group], 1000);

        self::assertEquals(['fetchedAt' => 1000, 'groups' => [$group]], $cache->get('jdoe'), 'the entry round-trips');
    }

    public function testTheSessionIsStartedWhenNeeded(): void
    {
        self::assertSame(PHP_SESSION_NONE, session_status(), 'precondition: no session yet');

        new MembershipCache(new Session())->get('jdoe');

        self::assertSame(PHP_SESSION_ACTIVE, session_status(), 'the cache starts a session so the CAS path has one');
    }

    public function testAnEntryForAnotherUserIsAMiss(): void
    {
        $cache = new MembershipCache(new Session());
        $cache->put('jdoe', [], 1000);

        self::assertNull($cache->get('asmith'), 'a different username never reads another user\'s groups');
    }

    public function testGroupsAreStoredAsPlainArrays(): void
    {
        new MembershipCache(new Session())->put('jdoe', [new GrouperGroup('iu:x', 'IU:X', 'X', 'uuid')], 1000);

        $stored = $_SESSION['guild_framework_membership'] ?? null;

        self::assertIsArray($stored, 'the entry is in the session');
        self::assertIsArray($stored['groups'], 'groups are a list');
        self::assertIsArray($stored['groups'][0], 'no serialized objects, so a grouper upgrade cannot break live sessions');
    }

    public function testAMalformedEntryIsAMiss(): void
    {
        session_start();
        $_SESSION['guild_framework_membership'] = [
            'username' => 'jdoe',
            'fetched_at' => 1000,
            'groups' => [['identifier' => 'iu:x']],
        ];

        self::assertNull(new MembershipCache(new Session())->get('jdoe'), 'an unexpected shape is treated as no entry');
    }
}
