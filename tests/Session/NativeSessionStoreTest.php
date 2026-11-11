<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Session;

use Guild\Framework\Session\NativeSessionStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The one store that touches the real PHP session, so each test runs in its own process.
 */
#[CoversClass(NativeSessionStore::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NativeSessionStoreTest extends TestCase
{
    public function testValuesAreStoredInTheNativeSession(): void
    {
        new NativeSessionStore()->put('k', 'v');

        self::assertSame('v', $_SESSION['k'] ?? null, 'the store writes through to $_SESSION');
    }

    public function testTheSessionIsStartedWhenNeeded(): void
    {
        self::assertSame(PHP_SESSION_NONE, session_status(), 'precondition: no session yet');

        new NativeSessionStore()->get('k');

        self::assertSame(PHP_SESSION_ACTIVE, session_status(), 'the store starts a session, so the CAS path has one');
    }

    public function testRegenerateIdBeforeAnyUseStartsTheSessionAndChangesTheId(): void
    {
        $store = new NativeSessionStore();

        $store->regenerateId();

        self::assertSame(PHP_SESSION_ACTIVE, session_status(), 'regenerating first starts the session');

        $first = session_id();
        $store->regenerateId();

        self::assertNotSame($first, session_id(), 'each regeneration issues a new session ID');
    }

    public function testForgetRemovesTheKey(): void
    {
        $store = new NativeSessionStore();
        $store->put('k', 'v');

        $store->forget('k');

        self::assertArrayNotHasKey('k', $_SESSION, 'a forgotten key is unset, not set to null');
        self::assertNull($store->get('k'), 'and reads as absent');
    }
}
