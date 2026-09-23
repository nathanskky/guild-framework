<?php

declare(strict_types=1);

namespace Guild\Framework\Authorization\Membership;

use Guild\Grouper\GrouperGroup;

/**
 * A user's Grouper group membership, and whether it is current.
 *
 * @internal
 */
final readonly class Membership
{
    /**
     * @param  list<GrouperGroup>  $groups
     * @param  bool  $fresh  False when Grouper was unreachable and this was served from
     *                       a cache entry past its TTL but within the stale cap.
     * @param  int  $fetchedAt  Unix time the groups were retrieved from Grouper.
     */
    public function __construct(
        public array $groups,
        public bool $fresh,
        public int $fetchedAt,
    ) {
    }
}
