<?php

declare(strict_types=1);

namespace Guild\Framework\Authorization;

use Attribute;

/**
 * Declares the resource class a policy governs.
 *
 * Read only on the policy classes passed to addAuthorization(); nothing is
 * discovered. A check on a resource matches its exact class, so a subclass
 * needs its own policy.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class HandlesResource
{
    /**
     * @param  class-string  $resource
     */
    public function __construct(public string $resource)
    {
    }
}
