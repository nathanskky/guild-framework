<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authorization\Membership;

use Guild\Framework\Testing\ArraySessionStore;
use Guild\Framework\Authorization\Membership\MembershipCache;
use Guild\Grouper\GrouperGroup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MembershipCache::class)]
#[UsesClass(ArraySessionStore::class)]
final class MembershipCacheTest extends TestCase
{
    public function testAStoredMembershipReadsBack(): void
    {
        $cache = new MembershipCache(new ArraySessionStore());
        $group = new GrouperGroup('iu:roles:sys:acm:x-editors', 'IU:Roles:X Editors', 'X Editors', 'uuid-1', 'Editors of X');

        $cache->put('jdoe', [$group], 1000);

        self::assertEquals(['fetchedAt' => 1000, 'groups' => [$group]], $cache->get('jdoe'), 'the entry round-trips');
    }

    public function testAnEntryForAnotherUserIsAMiss(): void
    {
        $cache = new MembershipCache(new ArraySessionStore());
        $cache->put('jdoe', [], 1000);

        self::assertNull($cache->get('asmith'), 'a different username never reads another user\'s groups');
    }

    public function testGroupsAreStoredAsPlainArrays(): void
    {
        $store = new ArraySessionStore();
        new MembershipCache($store)->put('jdoe', [new GrouperGroup('iu:x', 'IU:X', 'X', 'uuid')], 1000);

        $stored = $store->get('guild_framework_membership');

        self::assertIsArray($stored, 'the entry is in the session');
        self::assertIsArray($stored['groups'], 'groups are a list');
        self::assertIsArray($stored['groups'][0], 'no serialized objects, so a grouper upgrade cannot break live sessions');
    }

    public function testAMalformedEntryIsAMiss(): void
    {
        $store = new ArraySessionStore(['guild_framework_membership' => [
            'username' => 'jdoe',
            'fetched_at' => 1000,
            'groups' => [['identifier' => 'iu:x']],
        ]]);

        self::assertNull(new MembershipCache($store)->get('jdoe'), 'an unexpected shape is treated as no entry');
    }

    public function testAnEntryWrittenUnderTheExistingKeyReadsBack(): void
    {
        $store = new ArraySessionStore(['guild_framework_membership' => [
            'username' => 'jdoe',
            'fetched_at' => 1000,
            'groups' => [['identifier' => 'iu:x', 'displayName' => 'IU:X', 'displayExtension' => 'X', 'uuid' => 'u', 'description' => null]],
        ]]);

        self::assertSame(1000, new MembershipCache($store)->get('jdoe')['fetchedAt'] ?? null, 'sessions live before an upgrade keep their cached membership');
    }
}
