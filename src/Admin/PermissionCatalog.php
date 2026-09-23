<?php

declare(strict_types=1);

namespace Guild\Framework\Admin;

use Guild\Framework\Authorization\Permission;

/**
 * The application's permission enum, as the administration pages need it.
 *
 * @internal
 */
final readonly class PermissionCatalog
{
    /**
     * @param  class-string<Permission>  $enum
     */
    public function __construct(private string $enum)
    {
    }

    /**
     * @return list<Permission>
     */
    public function cases(): array
    {
        return ($this->enum)::cases();
    }

    public function isKnown(string $name): bool
    {
        return ($this->enum)::tryFrom($name) !== null;
    }
}
