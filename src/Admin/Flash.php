<?php

declare(strict_types=1);

namespace Guild\Framework\Admin;

use Guild\Framework\Session\SessionStore;

/**
 * A message carried across one redirect, for post/redirect/get.
 *
 * @internal
 */
final readonly class Flash
{
    private const string KEY = 'guild_framework_flash';

    public function __construct(private SessionStore $session)
    {
    }

    public function set(string $message): void
    {
        $this->session->put(self::KEY, $message);
    }

    /**
     * The pending message, which is removed as it is read.
     */
    public function take(): ?string
    {
        $message = $this->session->get(self::KEY);
        $this->session->forget(self::KEY);

        return is_string($message) ? $message : null;
    }
}
