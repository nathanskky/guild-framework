<?php

declare(strict_types=1);

namespace Guild\Framework\Authorization;

use Attribute;

/**
 * Binds a policy method to the permission it decides for its resource.
 *
 * The method takes the acting user first — `User`, or `?User` to also be
 * asked about guests — and the resource second, and returns bool. It is only
 * consulted after the user's roles are confirmed to grant the permission, so
 * it decides which instances, not whether the user holds the capability.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Handles
{
    public function __construct(public Permission $permission)
    {
    }
}
