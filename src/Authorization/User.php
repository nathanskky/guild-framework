<?php

declare(strict_types=1);

namespace Guild\Framework\Authorization;

use Guild\Framework\Authorization\Identity\Identity;
use Guild\Framework\Authorization\Membership\Membership;
use Guild\Framework\Authorization\Membership\MembershipProvider;
use Guild\Framework\Exception\AuthorizationUnavailableException;
use Guild\Framework\Exception\ConfigurationException;
use Guild\Grouper\GrouperGroup;

/**
 * The authenticated user making the current request. Obtained from
 * UserResolver; a guest is null, never a User.
 *
 * Nothing is loaded on construction. The first call that needs group
 * membership or grants loads them, and later calls in the same request reuse
 * the result. A failure to determine membership is remembered in the same way,
 * so an outage costs one Grouper timeout per request rather than one per check.
 *
 * Methods that depend on group membership throw
 * AuthorizationUnavailableException when it cannot be determined.
 */
final class User
{
    private ?Membership $membership = null;

    private ?AuthorizationUnavailableException $unavailable = null;

    /**
     * @var array{roles: list<string>, permissions: list<string>}|null
     */
    private ?array $grants = null;

    /**
     * @internal Constructed by UserResolver.
     *
     * @param  class-string<Permission>  $permissionEnum
     */
    public function __construct(
        private readonly Identity $identity,
        private readonly MembershipProvider $memberships,
        private readonly GrantRepository $grantRepository,
        private readonly string $permissionEnum,
        private readonly string $systemAdminGroup,
    ) {
    }

    /**
     * The IU username. Suitable for storing in ownership and audit columns.
     */
    public function username(): string
    {
        return $this->identity->username;
    }

    /**
     * An OIDC userinfo claim, or null if it is absent. Always null when the
     * identity source is CAS, which provides nothing beyond the username.
     */
    public function attribute(string $claim): mixed
    {
        return $this->identity->attributes[$claim] ?? null;
    }

    /**
     * @return list<UserGroup>
     *
     * @throws AuthorizationUnavailableException
     */
    public function groups(): array
    {
        return array_map(
            static fn (GrouperGroup $group): UserGroup => new UserGroup($group->identifier, $group->displayExtension),
            $this->membership()->groups,
        );
    }

    /**
     * @param  string  $identifier  A Grouper system name, e.g. 'iu:roles:sys:acm:your-app-editors'.
     *
     * @throws AuthorizationUnavailableException
     */
    public function inGroup(string $identifier): bool
    {
        foreach ($this->membership()->groups as $group) {
            if ($group->identifier === $identifier) {
                return true;
            }
        }

        return false;
    }

    /**
     * Names of the active roles mapped to the user's active groups.
     *
     * @return list<string>
     *
     * @throws AuthorizationUnavailableException
     */
    public function roles(): array
    {
        return $this->grants()['roles'];
    }

    /**
     * The permissions the user's roles grant. A stored grant whose name is no
     * longer a case of the permission enum grants nothing and is left out.
     *
     * @return list<Permission>
     *
     * @throws AuthorizationUnavailableException
     */
    public function permissions(): array
    {
        $enum = $this->permissionEnum;
        $permissions = [];

        foreach ($this->grants()['permissions'] as $name) {
            $permission = $enum::tryFrom($name);

            if ($permission !== null) {
                $permissions[] = $permission;
            }
        }

        return $permissions;
    }

    /**
     * @throws AuthorizationUnavailableException
     * @throws ConfigurationException if $permission is not a case of the registered permission enum
     */
    public function hasPermission(Permission $permission): bool
    {
        if (! $permission instanceof $this->permissionEnum) {
            throw new ConfigurationException(sprintf(
                '%s::%s is not a case of the registered permission enum %s.',
                $permission::class,
                $permission->name,
                $this->permissionEnum,
            ));
        }

        return in_array((string) $permission->value, $this->grants()['permissions'], true);
    }

    /**
     * Whether the user is in the configured System Admin group.
     *
     * Only current membership is used, never stale cache: this check bypasses
     * every other one, so it fails closed as soon as membership cannot be
     * confirmed. "Could not confirm" throws rather than returning false.
     *
     * @throws AuthorizationUnavailableException when current membership cannot be determined
     * @throws ConfigurationException when more than one of the user's groups carries the label
     */
    public function isSystemAdmin(): bool
    {
        $membership = $this->membership();

        if (! $membership->fresh) {
            throw new AuthorizationUnavailableException(
                'Whether this user is a system administrator cannot be confirmed: Grouper is unavailable, '
                . 'and cached membership is never used for this check.'
            );
        }

        $matches = array_values(array_filter(
            $membership->groups,
            fn (GrouperGroup $group): bool => $group->displayExtension === $this->systemAdminGroup,
        ));

        if (count($matches) > 1) {
            throw new ConfigurationException(sprintf(
                "The system admin group label '%s' matches more than one group: %s. "
                . 'Rename one of them in ACM, or configure a label that is unique.',
                $this->systemAdminGroup,
                implode(', ', array_map(static fn (GrouperGroup $group): string => $group->identifier, $matches)),
            ));
        }

        return count($matches) === 1;
    }

    private function membership(): Membership
    {
        if ($this->unavailable !== null) {
            throw $this->unavailable;
        }

        if ($this->membership === null) {
            try {
                $this->membership = $this->memberships->membershipFor($this->identity->username);
            } catch (AuthorizationUnavailableException $exception) {
                $this->unavailable = $exception;

                throw $exception;
            }
        }

        return $this->membership;
    }

    /**
     * @return array{roles: list<string>, permissions: list<string>}
     */
    private function grants(): array
    {
        return $this->grants ??= $this->grantRepository->grantsFor(array_map(
            static fn (GrouperGroup $group): string => $group->identifier,
            $this->membership()->groups,
        ));
    }
}
