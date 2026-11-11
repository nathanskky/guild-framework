<?php

declare(strict_types=1);

namespace Guild\Framework\Testing;

use Guild\Framework\Session\SessionStore;
use Override;

/**
 * An in-memory session for tests, so they need neither a real PHP session
 * nor a separate process.
 *
 * Inert unless test code constructs it; nothing in the framework binds it.
 */
final class ArraySessionStore implements SessionStore
{
    /**
     * How many times regenerateId() has been called.
     */
    public private(set) int $regenerations = 0;

    /**
     * @param  array<string, mixed>  $values  The session's contents before the test begins.
     */
    public function __construct(private array $values = [])
    {
    }

    #[Override]
    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    #[Override]
    public function put(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    #[Override]
    public function forget(string $key): void
    {
        unset($this->values[$key]);
    }

    #[Override]
    public function regenerateId(): void
    {
        $this->regenerations++;
    }

    /**
     * Everything in the session, for assertions.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }
}
