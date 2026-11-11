<?php

declare(strict_types=1);

namespace Guild\Framework\Session;

/**
 * This visitor's session data.
 *
 * Framework code reads and writes the session only through this interface,
 * so tests can swap in Testing\ArraySessionStore and run in one process.
 */
interface SessionStore
{
    /**
     * The value stored under $key, or null when there is none.
     */
    public function get(string $key): mixed;

    public function put(string $key, mixed $value): void;

    public function forget(string $key): void;

    /**
     * Issue a new session ID and keep the data, e.g. when the signed-in user
     * changes, so a session ID known before the change is worthless after it.
     */
    public function regenerateId(): void;
}
