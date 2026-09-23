<?php

declare(strict_types=1);

namespace Guild\Framework\Authorization;

use Closure;
use Guild\Framework\Exception\AuthorizationUnavailableException;
use Guild\Framework\Exception\ConfigurationException;

/**
 * The engine-neutral half of the template can()/cannot() functions.
 *
 * Templates may name a permission by its case or by its stored value
 * ('documents.update'). Templates are not statically analysed either way, so
 * the value string costs nothing a case would have caught; an unknown value
 * throws when the template renders rather than quietly denying.
 *
 * The Gate is resolved on first use, so a page that never checks costs
 * nothing.
 *
 * @internal
 */
final class TemplateAuthorization
{
    private ?Gate $gate = null;

    /**
     * @param  Closure(): Gate  $resolveGate
     * @param  class-string<Permission>  $permissionEnum
     */
    public function __construct(
        private readonly Closure $resolveGate,
        private readonly string $permissionEnum,
    ) {
    }

    /**
     * @throws AuthorizationUnavailableException
     * @throws ConfigurationException
     */
    public function can(Permission|string $permission, ?object $resource = null): bool
    {
        return $this->gate()->allows($this->permission($permission), $resource);
    }

    /**
     * @throws AuthorizationUnavailableException
     * @throws ConfigurationException
     */
    public function cannot(Permission|string $permission, ?object $resource = null): bool
    {
        return ! $this->can($permission, $resource);
    }

    private function permission(Permission|string $permission): Permission
    {
        if ($permission instanceof Permission) {
            return $permission;
        }

        $enum = $this->permissionEnum;

        return $enum::tryFrom($permission) ?? throw new ConfigurationException(
            "'{$permission}' is not a permission. It must be the value of a case of {$enum}."
        );
    }

    private function gate(): Gate
    {
        return $this->gate ??= ($this->resolveGate)();
    }
}
