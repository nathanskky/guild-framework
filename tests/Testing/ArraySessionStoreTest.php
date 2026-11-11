<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Testing;

use Guild\Framework\Testing\ArraySessionStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArraySessionStore::class)]
final class ArraySessionStoreTest extends TestCase
{
    public function testAPutValueReadsBack(): void
    {
        $store = new ArraySessionStore();

        $store->put('k', ['a' => 1]);

        self::assertSame(['a' => 1], $store->get('k'), 'a stored value reads back unchanged');
    }

    public function testAMissingKeyReadsAsNull(): void
    {
        self::assertNull(new ArraySessionStore()->get('missing'), 'an absent key is null, like an unset $_SESSION entry');
    }

    public function testForgetRemovesTheKey(): void
    {
        $store = new ArraySessionStore(['k' => 'v']);

        $store->forget('k');

        self::assertNull($store->get('k'), 'a forgotten key reads as absent');
        self::assertSame([], $store->all(), 'nothing is left behind');
    }

    public function testInitialValuesAreReadable(): void
    {
        $store = new ArraySessionStore(['guild_framework_csrf' => 'test-token']);

        self::assertSame('test-token', $store->get('guild_framework_csrf'), 'a test can seed the session before a request');
    }

    public function testRegenerateIdIsCountedAndKeepsValues(): void
    {
        $store = new ArraySessionStore(['k' => 'v']);

        $store->regenerateId();

        self::assertSame(1, $store->regenerations, 'tests can assert the session ID was regenerated');
        self::assertSame('v', $store->get('k'), 'regenerating the ID keeps the data, as session_regenerate_id() does');
    }
}
