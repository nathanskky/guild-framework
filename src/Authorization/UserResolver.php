<?php

declare(strict_types=1);

namespace Guild\Framework\Authorization;

use Guild\Framework\Authorization\Identity\IdentityReader;
use Guild\Framework\Authorization\Membership\MembershipProvider;

/**
 * Hands out the current request's User, or null for a guest.
 *
 * Resolves once per request: every caller gets the same User, so data it has
 * loaded is shared. Constructor-inject this rather than User itself.
 */
final class UserResolver
{
    private bool $resolved = false;

    private ?User $user = null;

    /**
     * @param  class-string<Permission>  $permissionEnum
     */
    public function __construct(
        private readonly IdentityReader $identities,
        private readonly MembershipProvider $memberships,
        private readonly GrantRepository $grants,
        private readonly string $permissionEnum,
        private readonly string $systemAdminGroup,
    ) {
    }

    public function current(): ?User
    {
        if (! $this->resolved) {
            $identity = $this->identities->read();

            $this->user = $identity === null ? null : new User(
                $identity,
                $this->memberships,
                $this->grants,
                $this->permissionEnum,
                $this->systemAdminGroup,
            );
            $this->resolved = true;
        }

        return $this->user;
    }
}
