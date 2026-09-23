<?php

declare(strict_types=1);

namespace Guild\Framework\Admin;

use Guild\Framework\Authorization\UserResolver;
use LogicException;

/**
 * The administrator making the current change, for the audit columns.
 *
 * @internal
 */
final readonly class Actor
{
    public function __construct(private UserResolver $users)
    {
    }

    /**
     * The IU username stored in created_by and updated_by.
     */
    public function username(): string
    {
        // RequireSystemAdmin runs before every administration route, so a
        // guest here is a routing mistake, not a user error.
        return $this->users->current()?->username()
            ?? throw new LogicException('An administration change was made without a signed-in user.');
    }
}
