<?php

declare(strict_types=1);

namespace Guild\Framework\Authorization\Policy;

use Guild\Framework\Authorization\User;
use ReflectionMethod;

/**
 * One policy method, resolved and validated, ready to decide.
 *
 * @internal
 */
final readonly class PolicyMethod
{
    public function __construct(
        private object $policy,
        private ReflectionMethod $method,
        public bool $allowsGuests,
    ) {
    }

    public function decide(?User $user, object $resource): bool
    {
        return $this->method->invoke($this->policy, $user, $resource) === true;
    }
}
