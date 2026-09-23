<?php

declare(strict_types=1);

namespace Guild\Framework\Authorization;

use Guild\Framework\Authorization\Policy\PolicyRegistry;
use Guild\Framework\Exception\AuthenticationRequiredException;
use Guild\Framework\Exception\AuthorizationException;
use Guild\Framework\Exception\AuthorizationUnavailableException;
use Guild\Framework\Exception\ConfigurationException;

/**
 * Answers "may the current user do this?", optionally about one resource.
 *
 * - Without a resource, the user's roles decide: they must grant the permission.
 * - With a resource, the roles must grant it and the resource's policy method
 *   must agree. The policy is resolved first, so a policy mistake throws for
 *   every user rather than only for those who hold the grant.
 * - A member of the System Admin group is allowed everything.
 * - A guest holds no grants. Only a policy method whose user parameter is
 *   nullable is asked about a guest; otherwise a guest is denied.
 *
 * When group membership cannot be determined, checks throw
 * AuthorizationUnavailableException rather than answering false.
 */
final readonly class Gate
{
    /**
     * @param  class-string<Permission>  $permissionEnum
     */
    public function __construct(
        private UserResolver $users,
        private PolicyRegistry $policies,
        private string $permissionEnum,
    ) {
    }

    /**
     * @throws AuthorizationUnavailableException
     * @throws ConfigurationException
     */
    public function allows(Permission $permission, ?object $resource = null): bool
    {
        if (! $permission instanceof $this->permissionEnum) {
            throw new ConfigurationException(sprintf(
                '%s::%s is not a case of the registered permission enum %s.',
                $permission::class,
                $permission->name,
                $this->permissionEnum,
            ));
        }

        $policyMethod = $resource === null ? null : $this->policies->methodFor($resource, $permission);
        $user = $this->users->current();

        if ($user === null) {
            return $resource !== null
                && $policyMethod !== null
                && $policyMethod->allowsGuests
                && $policyMethod->decide(null, $resource);
        }

        if ($this->isSystemAdmin($user)) {
            return true;
        }

        if (! $user->hasPermission($permission)) {
            return false;
        }

        return $resource === null || $policyMethod === null || $policyMethod->decide($user, $resource);
    }

    /**
     * @throws AuthorizationUnavailableException
     * @throws ConfigurationException
     */
    public function denies(Permission $permission, ?object $resource = null): bool
    {
        return ! $this->allows($permission, $resource);
    }

    /**
     * @throws AuthenticationRequiredException when a guest is denied
     * @throws AuthorizationException when an authenticated user is denied
     * @throws AuthorizationUnavailableException
     * @throws ConfigurationException
     */
    public function authorize(Permission $permission, ?object $resource = null): void
    {
        if ($this->allows($permission, $resource)) {
            return;
        }

        $subject = sprintf(
            '%s::%s%s',
            $permission::class,
            $permission->name,
            $resource === null ? '' : ' on ' . $resource::class,
        );

        if ($this->users->current() === null) {
            throw new AuthenticationRequiredException("Authentication is required for {$subject}.");
        }

        throw new AuthorizationException("Not authorized for {$subject}.");
    }

    /**
     * The System Admin bypass. When membership is too stale to confirm it,
     * the bypass does not apply and the ordinary rules decide instead.
     */
    private function isSystemAdmin(User $user): bool
    {
        try {
            return $user->isSystemAdmin();
        } catch (AuthorizationUnavailableException) {
            return false;
        }
    }
}
