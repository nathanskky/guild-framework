<?php

declare(strict_types=1);

namespace Guild\Framework\Admin;

use Guild\Framework\Authorization\Membership\Session;

/**
 * A message carried across one redirect, for post/redirect/get.
 *
 * @internal
 */
final readonly class Flash
{
    private const string KEY = 'guild_framework_flash';

    public function __construct(private Session $session)
    {
    }

    public function set(string $message): void
    {
        $this->session->ensureStarted();
        $_SESSION[self::KEY] = $message;
    }

    /**
     * The pending message, which is removed as it is read.
     */
    public function take(): ?string
    {
        $this->session->ensureStarted();

        $message = $_SESSION[self::KEY] ?? null;
        unset($_SESSION[self::KEY]);

        return is_string($message) ? $message : null;
    }
}
